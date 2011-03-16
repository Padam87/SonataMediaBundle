<?php

/*
 * This file is part of the Sonata project.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\MediaBundle\Provider;

use Sonata\MediaBundle\Entity\BaseMedia as Media;
use Symfony\Component\Form\Form;
use Sonata\MediaBundle\Media\ResizerInterface;
use Gaufrette\Filesystem\Filesystem;

abstract class BaseProvider
{
    /**
     * @var array
     */
    protected $formats = array();

    /**
     * @var array
     */
    protected $settings = array();

    protected $em;

    protected $templates = array();

    protected $resizer;

    protected $filesystem;

    /**
     * @param string $name
     * @param \Doctrine\ORM\EntityManager $em
     * @param array $settings
     */
    public function __construct($name, $em, Filesystem $filesystem, array $settings = array())
    {
        $this->name         = $name;
        $this->em           = $em;
        $this->settings     = $settings;
        $this->filesystem   = $filesystem;
    }

    /**
     * @param string $name
     * @param array $format
     *
     * @return void
     */
    public function addFormat($name, $format)
    {
        $this->formats[$name] = $format;
    }

    /**
     * return the format settings
     * 
     * @param string $name
     *
     * @return array|false the format settings
     */
    public function getFormat($name)
    {
        return isset($this->formats[$name]) ? $this->formats[$name] : false;
    }

    /**
     * return true if the media related to the provider required thumbnails (generation)
     *
     * @return boolean
     */
    public function requireThumbnails()
    {
        return $this->getResizer() !== null;
    }

    /**
     * generated thumbnails linked to the media, a thumbnail is a format used on the website
     *
     * @return void
     */
    public function generateThumbnails(Media $media)
    {
        if (!$this->requireThumbnails()) {
            return;
        }

        if (!is_array($this->formats) || count($this->formats) == 0) {
            throw new \RuntimeException('You must define one format');
        }

        $key = $this->getReferenceImage($media);

        if (substr($key, 0, 7) == 'http://') {
            $key = $this->generatePrivateUrl($media, 'reference');

            // the reference file is remote, get it and store it with the 'reference' format
            if($this->getFilesystem()->has($key)) {
                $referenceFile = $this->getFilesystem()->get($key);
            } else {
                $referenceFile = $this->getFilesystem()->get($key, true);
                $referenceFile->setContent(file_get_contents($this->getReferenceImage($media)));
            }
        } else {
            $referenceFile = $this->getFilesystem()->get($this->getReferenceImage($media), true);
        }

        foreach ($this->formats as $format => $settings) {

            // resize the thumbnail
            $this->getResizer()->resize(
                $referenceFile,
                $this->getFilesystem()->get($this->generatePrivateUrl($media, $format), true),
                'jpg' ,
                $settings['width'],
                $settings['height']
            );
        }
    }

    /**
     * return the reference image of the media, can be the videa thumbnail or the original uploaded picture
     *
     * @abstract
     * @return string to the reference image
     */
    abstract function getReferenceImage(Media $media);

    /**
     * return the absolute path of the reference image or the service provider reference
     *
     * @abstract
     * @return void
     */
    abstract function getAbsolutePath(Media $media);

    /**
     *
     * @abstract
     * @param  $media
     * @return void
     */
    abstract function postUpdate(Media $media);

    /**
     * @param \Sonata\MediaBundle\Entity\BaseMedia $media
     * @return void
     */
    public function postRemove(Media $media)
    {
        $path = $this->getReferenceImage($media);

        if($this->getFilesystem()->has($path)) {
            $this->getFilesystem()->delete($path);
        }

        // delete the differents formats
        foreach ($this->formats as $format => $definition) {
            $path = $this->generatePrivateUrl($media, $format);
            if($this->getFilesystem()->has($path)) {
                $this->getFilesystem()->delete($path);
            }
        }
    }

    /**
     * build the related create form
     *
     */
    abstract function buildCreateForm(Form $form);

    /**
     * build the related create form
     *
     */
    abstract function buildEditForm(Form $form);

    /**
     *
     * @abstract
     * @param  $media
     * @return void
     */
    abstract function postPersist(Media $media);

    /**
     * @param \Sonata\MediaBundle\Entity\BaseMedia $media
     * @param string $format
     */
    abstract function getHelperProperties(Media $media, $format);

    /**
     * Generate the private path (client side)
     *
     * @param \Sonata\MediaBundle\Entity\BaseMedia $media
     * @return string
     */
    public function generatePrivatePath(Media $media)
    {
        $limit_first_level = 100000;
        $limit_second_level = 1000;

        $rep_first_level = (int) ($media->getId() / $limit_first_level);
        $rep_second_level = (int) (($media->getId() - ($rep_first_level * $limit_first_level)) / $limit_second_level);
        $path = sprintf('%04s/%02s',
            $rep_first_level + 1,
            $rep_second_level + 1
        );

        return $path;
    }

    /**
     * Generate the public path (client side)
     *
     * @param \Sonata\MediaBundle\Entity\BaseMedia $media
     * @return string
     */
    public function generatePublicPath(Media $media)
    {
        $limit_first_level = 100000;
        $limit_second_level = 1000;

        $rep_first_level = (int) ($media->getId() / $limit_first_level);
        $rep_second_level = (int) (($media->getId() - ($rep_first_level * $limit_first_level)) / $limit_second_level);
        $path = sprintf('%s/%04s/%02s', // todo : allow this to be configured....
            $this->settings['public_path'],
            $rep_first_level + 1,
            $rep_second_level + 1
        );

        return $path;
    }

    /**
     * Generate the public directory path (client side)
     *
     * @param \Sonata\MediaBundle\Entity\BaseMedia $media
     * @param  $format
     * @return string
     */
    public function generatePublicUrl(Media $media, $format)
    {

        return sprintf('%s/thumb_%d_%s.jpg',
            $this->generatePublicPath($media),
            $media->getId(),
            $format
        );
    }

    /**
     * Generate the private directory path (server side)
     *
     * @param \Sonata\MediaBundle\Entity\BaseMedia $media
     * @param  $format
     * @return string
     */
    public function generatePrivateUrl(Media $media, $format)
    {

        return sprintf('%s/thumb_%d_%s.jpg',
            $this->generatePrivatePath($media),
            $media->getId(),
            $format
        );
    }
    
    public function getFormats()
    {

        return $this->formats;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setSettings(array $settings)
    {
        $this->settings = $settings;
    }

    public function getSettings()
    {
        return $this->settings;
    }

    /**
     *
     * @param array $templates
     */
    public function setTemplates(array $templates)
    {
        $this->templates = $templates;
    }

    /**
     *
     * @return array
     */
    public function getTemplates()
    {
        return $this->templates;
    }

    /**
     * @param string $name
     * @return void
     */
    public function getTemplate($name)
    {
        return isset($this->templates[$name]) ? $this->templates[$name] : null; 
    }

    /**
     * @return \Sonata\MediaBundle\Media\ResizerInterface
     */
    public function getResizer()
    {
        return $this->resizer;
    }

    public function setResizer(ResizerInterface $resizer)
    {
        return $this->resizer = $resizer;
    }

    public function setFilesystem($filesystem)
    {
        $this->filesystem = $filesystem;
    }

    public function getFilesystem()
    {
        return $this->filesystem;
    }
}
