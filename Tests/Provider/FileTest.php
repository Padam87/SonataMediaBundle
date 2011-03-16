<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\MediaBundle\Tests\Provider;

use Sonata\MediaBundle\Tests\Entity\Media;

class FileProviderTest extends \PHPUnit_Framework_TestCase
{

    public function getProvider()
    {
        $em = 1;
        $settings = array (
            'cdn_enabled'   => true,
            'cdn_path'      => 'http://here.com',
            'private_path'  => '/fake/path',
            'public_path'   => '/updoads/media',
        );

        $resizer = $this->getMock('Sonata\MediaBundle\Media\ResizerInterface', array('resize'));
        $resizer->expects($this->any())
            ->method('resize')
            ->will($this->returnValue(true));


        $adapter = $this->getMock('Gaufrette\Filesystem\Adapter');

        $file = $this->getMock('Gaufrette\Filesystem\File', array(), array($adapter));

        $filesystem = $this->getMock('Gaufrette\Filesystem\Filesystem', array('get'), array($adapter));
        $filesystem->expects($this->any())
            ->method('get')
            ->will($this->returnValue($file));


        $provider = new \Sonata\MediaBundle\Provider\FileProvider('file', $em, $filesystem, $settings);
        $provider->setResizer($resizer);

        return $provider;
    }

    
    public function testProvider()
    {

        $provider = $this->getProvider();

        $media = new Media;
        $media->setName('test.txt');
        $media->setProviderReference('ASDASD.txt');
        $media->setId(10);

        $this->assertEquals('0001/01/ASDASD.txt', $provider->getAbsolutePath($media), '::getAbsolutePath() return the correct path - id = 1');

        $media->setId(1023456);
        $this->assertEquals('0011/24/ASDASD.txt', $provider->getAbsolutePath($media), '::getAbsolutePath() return the correct path - id = 1023456');

        $this->assertEquals('0011/24/ASDASD.txt', $provider->getReferenceImage($media));

        $this->assertEquals('0011/24', $provider->generatePrivatePath($media));
        $this->assertEquals('/updoads/media/0011/24', $provider->generatePublicPath($media));

        // default icon image
        $this->assertEquals('/media_bundle/images/files/big/file.png', $provider->generatePublicUrl($media, 'big'));

    }

    public function testThumbnail()
    {

        $provider = $this->getProvider();

        $media = new Media;
        $media->setName('test.png');
        $media->setId(1023456);

        $provider->generateThumbnails($media);

    }

    public function testEvent()
    {

        $provider = $this->getProvider();

        $provider->addFormat('big', array('width' => 200, 'height' => 100, 'constraint' => true));

        $file = new \Symfony\Component\HttpFoundation\File\File(realpath(__DIR__.'/../fixtures/file.txt'));

        $media = new Media;
        $media->setBinaryContent($file);
        $media->setId(1023456);

        // pre persist the media
        $provider->prePersist($media);

        $this->assertEquals('file.txt', $media->getName(), '::getName() return the file name');
        $this->assertNotNull($media->getProviderReference(), '::getProviderReference() is set');

        // post persit the media
        $provider->postPersist($media);

        $this->assertFalse($provider->generatePrivateUrl($media, 'big'), '::generatePrivateUrl() return false');

        $provider->postRemove($media);
    }

}