<?php

namespace Jasny\HttpMessage;

use PHPUnit_Framework_TestCase;
use Jasny\HttpMessage\Tests\AssertLastError;
use Jasny\HttpMessage\ServerRequest;
use Jasny\HttpMessage\Stream;
use Jasny\HttpMessage\Uri;
use Jasny\HttpMessage\UploadedFile;
use Jasny\HttpMessage\DerivedAttribute;
use Jasny\HttpMessage\Headers as HeaderObject;

/**
 * @covers Jasny\HttpMessage\ServerRequest
 * @covers Jasny\HttpMessage\ServerRequest\GlobalEnvironment
 * @covers Jasny\HttpMessage\ServerRequest\ProtocolVersion
 * @covers Jasny\HttpMessage\Message\ProtocolVersion
 * @covers Jasny\HttpMessage\ServerRequest\Headers
 * @covers Jasny\HttpMessage\Message\Headers
 * @covers Jasny\HttpMessage\ServerRequest\Body
 * @covers Jasny\HttpMessage\Message\Body
 * @covers Jasny\HttpMessage\ServerRequest\RequestTarget
 * @covers Jasny\HttpMessage\ServerRequest\Method
 * @covers Jasny\HttpMessage\ServerRequest\Uri
 * @covers Jasny\HttpMessage\ServerRequest\ServerParams
 * @covers Jasny\HttpMessage\ServerRequest\Cookies
 * @covers Jasny\HttpMessage\ServerRequest\QueryParams
 * @covers Jasny\HttpMessage\ServerRequest\UploadedFiles
 * @covers Jasny\HttpMessage\ServerRequest\ParsedBody
 * @covers Jasny\HttpMessage\ServerRequest\Attributes
 */
class ServerRequestTest extends PHPUnit_Framework_TestCase
{
    use AssertLastError;
    
    /**
     * @var ServerRequest
     */
    protected $baseRequest;
    
    /**
     * @var HeadersInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $headers;

    public function setUp()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'headers');
        $refl->setAccessible(true);
        
        $this->baseRequest = new ServerRequest();
        $this->headers = $this->createMock(HeaderObject::class);
        $refl->setValue($this->baseRequest, $this->headers);
    }
    
    protected function setContentType($contentType)
    {
        $this->headers->expects($this->atLeastOnce())
            ->method('getHeaderLine')
            ->will($this->returnValue($contentType));
    }

    public function testWithGlobalEnvironment()
    {
        $request = $this->baseRequest->withGlobalEnvironment(false);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertNull($request->isStale());
    }

    public function testWithGlobalEnvironmentBind()
    {
        $request = $this->baseRequest->withGlobalEnvironment(true);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals('php://input', $request->getBody()
            ->getMetadata('uri'));
        
        $this->assertFalse($request->isStale());
        
        $this->assertSame($request, $request->withGlobalEnvironment());
    }

    public function testWithGlobalEnvironmentReset()
    {
        $request = $this->baseRequest->withMethod('POST')->withGlobalEnvironment();
        
        $this->assertEquals('', $request->getMethod());
    }

    /**
     * ServerRequest::setPostData is protected, because it should only be used for $_POST
     */
    public function testSetPostDataAndTurnStale()
    {
        $data = ['foo' => 'bar'];
        
        $this->headers->expects($this->once())
            ->method('withHeader')
            ->will($this->returnSelf());
        
        $this->headers->expects($this->atLeastOnce())
            ->method('getHeaderLine')
            ->will($this->returnValue('application/x-www-form-urlencoded'));
        
        $request = $this->baseRequest->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        $refl = new \ReflectionMethod(ServerRequest::class, 'setPostData');
        $refl->setAccessible(true);
        $refl->invokeArgs($request, [&$data]);
        
        $this->assertSame($data, $request->getParsedBody());
        
        // Test if data is set by reference
        $data['qux'] = 'zoo';
        $this->assertSame($data, $request->getParsedBody());
        
        // Test becoming stale
        $isStale = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $isStale->setAccessible(true);
        $isStale->setValue($request, false);
        
        $newRequest = $request->withParsedBody(['color' => 'blue']);
        $this->assertTrue($request->isStale());
        $this->assertFalse($newRequest->isStale());
        
        $this->assertSame(['color' => 'blue'], $data);
        $this->assertSame(['color' => 'blue'], $newRequest->getParsedBody());
        $this->assertSame(['foo' => 'bar', 'qux' => 'zoo'], $request->getParsedBody());
        
        $data = ['color' => 'red'];
        $this->assertSame($data, $newRequest->getParsedBody());
    }

    public function testWithoutGlobalEnvironmentDefault()
    {
        $this->assertSame($this->baseRequest, $this->baseRequest->withoutGlobalEnvironment());
    }

    public function testWithoutGlobalEnvironment()
    {
        $data = ['foo' => 'bar'];
        
        $this->headers->expects($this->once())
            ->method('withHeader')
            ->will($this->returnSelf());
        
        $this->setContentType('application/x-www-form-urlencoded');
        
        $request = $this->baseRequest->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        $refl = new \ReflectionMethod(ServerRequest::class, 'setPostData');
        $refl->setAccessible(true);
        $refl->invokeArgs($request, [&$data]);
        
        // Test becoming stale
        $isStale = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $isStale->setAccessible(true);
        $isStale->setValue($request, false);
        
        $detached = $request->withoutGlobalEnvironment();
        $this->assertFalse($request->isStale());
        $this->assertNull($detached->isStale());
        
        $data['qux'] = 'zoo';
        $this->assertEquals($data, $request->getParsedBody());
        $this->assertEquals(['foo' => 'bar'], $detached->getParsedBody());
    }

    public function testIsStale()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $refl->setAccessible(true);
        
        $this->assertNull($this->baseRequest->isStale());
        
        $refl->setValue($this->baseRequest, false);
        $this->assertFalse($this->baseRequest->isStale());
        
        $refl->setValue($this->baseRequest, true);
        $this->assertTrue($this->baseRequest->isStale());
    }

    public function testGetServerParamsDefault()
    {
        $this->assertSame([], $this->baseRequest->getServerParams());
    }

    public function testWithServerParams()
    {
        $params = ['SERVER_SOFTWARE' => 'Foo 1.0', 'COLOR' => 'red', 'SCRIPT_FILENAME' => 'qux.php'];
        
        $request = $this->baseRequest->withServerParams($params);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals($params, $request->getServerParams());
    }

    /**
     * @depends testWithServerParams
     */
    public function testWithServerParamsReset()
    {
        $request = $this->baseRequest->withMethod('POST')->withServerParams([]);
        
        $this->assertEquals('', $request->getMethod());
    }

    public function testWithServerParamsTurnStale()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $refl->setAccessible(true);
        $refl->setValue($this->baseRequest, false);
        
        $request = $this->baseRequest->withServerParams([]);
        
        $this->assertTrue($this->baseRequest->isStale());
        $this->assertFalse($request->isStale());
    }

    public function testDefaultProtocolVersion()
    {
        $this->assertEquals('1.1', $this->baseRequest->getProtocolVersion());
    }

    public function testDetermineProtocolVersion()
    {
        $request = $this->baseRequest->withServerParams(['SERVER_PROTOCOL' => 'HTTP/1.0']);
        
        $this->assertEquals('1.0', $request->getProtocolVersion());
    }

    public function testWithProtocolVersion()
    {
        $request = $this->baseRequest->withProtocolVersion('1.1');
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals('1.1', $request->getProtocolVersion());
    }

    public function testWithProtocolVersionFloat()
    {
        $request = $this->baseRequest->withProtocolVersion(2.0);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals('2.0', $request->getProtocolVersion());
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Invalid HTTP protocol version '0.2'
     */
    public function testWithInvalidProtocolVersion()
    {
        $this->baseRequest->withProtocolVersion('0.2');
    }

    
    /**
     * @internal Tight coupling in `initHeaders()` means `Headers::getHeaders()` must work properly
     */
    public function testDetermineHeaders()
    {
        $request = (new ServerRequest())->withServerParams([
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_FOO' => 'bar',
            'HTTP_ACCEPT_CHARSET' => 'utf-8',
            'HTTPS' => 'yes'
        ]);
        
        $this->assertEquals([
            'Content-Type' => ['text/plain'],
            'Foo' => ['bar'],
            'Accept-Charset' => ['utf-8']
        ], $request->getHeaders());
    }

    public function testWithHeader()
    {
        $this->headers->expects($this->once())
            ->method('withHeader')
            ->with('Foo', 'Baz')
            ->will($this->returnSelf());
        
        $request = $this->baseRequest->withHeader('Foo', 'Baz');
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
    }

    public function testWithAddedHeader()
    {
        $this->headers->expects($this->once())
            ->method('withAddedHeader')
            ->with('Foo', 'Baz')
            ->will($this->returnSelf());
        
        $request = $this->baseRequest->withAddedHeader('Foo', 'Baz');
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
    }

    public function testWithoutHeader()
    {
        $this->headers->expects($this->once())
            ->method('hasHeader')
            ->with('Foo')
            ->willReturn(true);
        
        $this->headers->expects($this->once())
            ->method('withoutHeader')
            ->with('Foo')
            ->will($this->returnSelf());
        
        $request = $this->baseRequest->withoutHeader('Foo');
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
    }

    public function testWithoutHeaderNonExistent()
    {
        $this->headers->expects($this->once())
            ->method('hasHeader')
            ->with('Foo')
            ->willReturn(false);
        
        $this->headers->expects($this->never())->method('withoutHeader');
        
        $request = $this->baseRequest->withoutHeader('Foo');
        
        $this->assertSame($this->baseRequest, $request);
    }

    public function testGetHeaders()
    {
        $this->headers->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['Foo' => ['bar'], 'Content-Type' => ['text/plain']]);
        
        $this->assertEquals(
            ['Foo' => ['bar'], 'Content-Type' => ['text/plain']],
            $this->baseRequest->getHeaders()
        );
    }

    public function testHasHeader()
    {
        $this->headers->expects($this->once())
            ->method('hasHeader')
            ->with('Foo')
            ->willReturn(true);
        
        $this->assertTrue($this->baseRequest->hasHeader('Foo'));
    }

    public function testGetHeaderLine()
    {
        $this->headers->expects($this->once())
            ->method('getHeaderLine')
            ->with('Foo')
            ->willReturn('Baz');
        
        $this->assertSame('Baz', $this->baseRequest->getHeaderLine('Foo'));
    }
    

    public function testGetBodyDefault()
    {
        $body = $this->baseRequest->getBody();
        
        $this->assertInstanceOf(Stream::class, $body);
        $this->assertEquals('data://text/plain,', $body->getMetadata('uri'));
    }

    public function testWithBody()
    {
        $stream = $this->createMock(Stream::class);
        $request = $this->baseRequest->withBody($stream);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame($stream, $request->getBody());
    }

    public function testGetRequestTargetDefault()
    {
        $this->assertEquals('/', $this->baseRequest->getRequestTarget());
    }

    public function testDetermineRequestTarget()
    {
        $request = $this->baseRequest->withServerParams(['REQUEST_URI' => '/foo?bar=1']);
        $this->assertEquals('/foo?bar=1', $request->getRequestTarget());
    }

    public function testDetermineRequestTargetOrigin()
    {
        $request = $this->baseRequest->withServerParams(['REQUEST_METHOD' => 'OPTIONS']);
        $this->assertEquals('*', $request->getRequestTarget());
    }

    public function testWithRequestTarget()
    {
        $request = $this->baseRequest->withRequestTarget('/foo?bar=99');
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals('/foo?bar=99', $request->getRequestTarget());
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Request target should be a string, not a stdClass object
     */
    public function testWithRequestTargetWithInvalidArgument()
    {
        $this->baseRequest->withRequestTarget((object)['foo' => 1, 'bar' => 2, 'zoo' => 3]);
    }

    public function testGetMethodDefault()
    {
        $this->assertSame('', $this->baseRequest->getMethod());
    }

    public function testDetermineMethod()
    {
        $request = $this->baseRequest->withServerParams(['REQUEST_METHOD' => 'post']);
        $this->assertEquals('POST', $request->getMethod());
    }

    public function testWithMethod()
    {
        $request = $this->baseRequest->withMethod('GeT');
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals('GET', $request->getMethod());
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Invalid method 'foo bar': Method may only contain letters and dashes
     */
    public function testWithMethodInvalid()
    {
        $this->baseRequest->withMethod("foo bar");
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Method should be a string, not a stdClass object
     */
    public function testWithMethodInvalidArgument()
    {
        $this->baseRequest->withMethod((object)['foo' => 1, 'bar' => 2]);
    }

    public function testGetUriDefault()
    {
        $uri = $this->baseRequest->getUri();
        
        $this->assertInstanceOf(Uri::class, $uri);
        $this->assertEquals(new Uri(), $uri);
    }

    public function testDetermineUri()
    {
        $request = $this->baseRequest->withServerParams([
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'PHP_AUTH_USER' => 'foo',
            'PHP_AUTH_PWD' => 'secure',
            'HTTP_HOST' => 'www.example.com:8080',
            'SERVER_PORT' => 8080,
            'REQUEST_URI' => '/page/bar?color=red',
            'QUERY_STRING' => 'color=red'
        ]);
        
        $this->assertEquals(new Uri([
            'scheme' => 'http',
            'user' => 'foo',
            'password' => 'secure',
            'host' => 'www.example.com',
            'port' => 8080,
            'path' => '/page/bar',
            'query' => 'color=red'
        ]), $request->getUri());
    }



    public function testDetermineUriHttps()
    {
        $protocol = ['SERVER_PROTOCOL' => 'HTTP/1.1'];
        $request = $this->baseRequest;
        
        $this->assertEquals('http', $request->withServerParams($protocol)
            ->getUri()
            ->getScheme());
        $this->assertEquals('http', $request->withServerParams($protocol + ['HTTPS' => ''])
            ->getUri()
            ->getScheme());
        $this->assertEquals('http', $request->withServerParams($protocol + ['HTTPS' => 'off'])
            ->getUri()
            ->getScheme());
        
        $this->assertEquals('https', $request->withServerParams($protocol + ['HTTPS' => '1'])
            ->getUri()
            ->getScheme());
        $this->assertEquals('https', $request->withServerParams($protocol + ['HTTPS' => 'on'])
            ->getUri()
            ->getScheme());
    }

    public function testWithUri()
    {
        $uri = $this->createMock(Uri::class);
        $uri->expects($this->once())
            ->method('getHost')
            ->willReturn('www.example.com');
        
        $this->headers->expects($this->once())
            ->method('withHeader')
            ->with('Host', 'www.example.com')
            ->will($this->returnSelf());
        
        $request = $this->baseRequest->withUri($uri);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame($uri, $request->getUri());
    }

    public function testWithUriPreserveHost()
    {
        $uri = $this->createMock(Uri::class);
        $uri->expects($this->never())
            ->method('getHost');
        
        $this->headers->expects($this->never())
            ->method('withHeader');
        
        $request = $this->baseRequest->withUri($uri, true);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame($uri, $request->getUri());
    }

    public function testGetCookieParamsDefault()
    {
        $this->assertSame([], $this->baseRequest->getCookieParams());
    }

    public function testWithCookieParams()
    {
        $request = $this->baseRequest->withCookieParams(['foo' => 'bar', 'color' => 'red']);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame(['foo' => 'bar', 'color' => 'red'], $request->getCookieParams());
    }

    public function testWithCookieParamsTurnStale()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $refl->setAccessible(true);
        $refl->setValue($this->baseRequest, false);
        
        $request = $this->baseRequest->withCookieParams([]);
        
        $this->assertTrue($this->baseRequest->isStale());
        $this->assertFalse($request->isStale());
    }

    public function testGetQueryParamsDefault()
    {
        $this->assertSame([], $this->baseRequest->getQueryParams());
    }

    public function testWithQueryParams()
    {
        $request = $this->baseRequest->withQueryParams(['foo' => 'bar', 'color' => 'red']);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame(['foo' => 'bar', 'color' => 'red'], $request->getQueryParams());
    }

    public function testWithQueryParamsTurnStale()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $refl->setAccessible(true);
        $refl->setValue($this->baseRequest, false);
        
        $request = $this->baseRequest->withQueryParams([]);
        
        $this->assertTrue($this->baseRequest->isStale());
        $this->assertFalse($request->isStale());
    }

    public function testGetUploadedFilesDefault()
    {
        $this->assertSame([], $this->baseRequest->getUploadedFiles());
    }

    /**
     * ServerRequest::setUploadFiles() is protected, because it can only be used for $_FILES
     */
    public function testSetUploadedFiles()
    {
        $refl = new \ReflectionMethod(ServerRequest::class, 'setUploadedFiles');
        $refl->setAccessible(true);
        
        $files = [
            'file' => [
                'name' => 'foo.txt',
                'type' => 'text/plain',
                'size' => 3,
                'tmp_name' => 'data://text/plain,foo',
                'error' => UPLOAD_ERR_OK
            ],
            'failed' => [
                'name' => '',
                'type' => '',
                'size' => '',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE
            ]
        ];
        
        $refl->invokeArgs($this->baseRequest, [&$files]);
        $uploadedFiles = $this->baseRequest->getUploadedFiles();
        
        $this->assertInternalType('array', $uploadedFiles);
        
        $this->assertArrayHasKey('file', $uploadedFiles);
        $this->assertInstanceOf(UploadedFile::class, $uploadedFiles['file']);
        $this->assertEquals(new UploadedFile($files['file'], 'file'), $uploadedFiles['file']);
        
        $this->assertArrayHasKey('failed', $uploadedFiles);
        $this->assertInstanceOf(UploadedFile::class, $uploadedFiles['failed']);
        $this->assertEquals(new UploadedFile($files['failed'], 'failed'), $uploadedFiles['failed']);
    }

    /**
     * ServerRequest::setUploadFiles() is protected, because it can only be used for $_FILES
     */
    public function testGroupUploadedFiles()
    {
        $files = [
            'file' => [
                'name' => 'foo.txt',
                'type' => 'text/plain',
                'size' => 3,
                'tmp_name' => 'data://text/plain,foo',
                'error' => UPLOAD_ERR_OK
            ],
            'colors' => [
                'name' => ['blue' => 'navy.txt', 'red' => 'cherry.html'],
                'type' => ['blue' => 'text/plain', 'red' => 'text/html'],
                'size' => ['blue' => 4, 'red' => 15],
                'tmp_name' => ['blue' => 'data://text/plain,navy', 'red' => 'data://text/html,<h1>cherry</h1>'],
                'error' => ['blue' => UPLOAD_ERR_OK, 'red' => UPLOAD_ERR_OK]
            ]
        ];
        
        $blue = ['name' => 'navy.txt', 'type' => 'text/plain', 'size' => 4, 'tmp_name' => 'data://text/plain,navy',
            'error' => UPLOAD_ERR_OK];
        $red = ['name' => 'cherry.html', 'type' => 'text/html', 'size' => 15,
            'tmp_name' => 'data://text/html,<h1>cherry</h1>', 'error' => UPLOAD_ERR_OK];
        
        $refl = new \ReflectionMethod(ServerRequest::class, 'setUploadedFiles');
        $refl->setAccessible(true);
        $refl->invokeArgs($this->baseRequest, [&$files]);
        
        $uploadedFiles = $this->baseRequest->getUploadedFiles();
        
        $this->assertInternalType('array', $uploadedFiles);
        
        $this->assertArrayHasKey('file', $uploadedFiles);
        $this->assertInstanceOf(UploadedFile::class, $uploadedFiles['file']);
        $this->assertEquals(new UploadedFile($files['file'], 'file'), $uploadedFiles['file']);
        
        $this->assertArrayHasKey('colors', $uploadedFiles);
        $this->assertInternalType('array', $uploadedFiles['colors']);
        
        $this->assertArrayHasKey('blue', $uploadedFiles['colors']);
        $this->assertInstanceOf(UploadedFile::class, $uploadedFiles['colors']['blue']);
        $this->assertEquals(new UploadedFile($blue, 'colors[blue]'), $uploadedFiles['colors']['blue']);
        
        $this->assertArrayHasKey('red', $uploadedFiles['colors']);
        $this->assertInstanceOf(UploadedFile::class, $uploadedFiles['colors']['red']);
        $this->assertEquals(new UploadedFile($red, 'colors[red]'), $uploadedFiles['colors']['red']);
    }

    public function testWithUploadedFiles()
    {
        $uploadedFile = $this->createMock(UploadedFile::class);
        $request = $this->baseRequest->withUploadedFiles(['file' => $uploadedFile]);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame(['file' => $uploadedFile], $request->getUploadedFiles());
    }

    public function testWithUploadedFilesStructure()
    {
        $file = $this->createMock(UploadedFile::class);
        $blue = clone $file;
        $red = clone $file;
        
        $files = ['file' => $file, 'colors' => compact('blue', 'red')];
        
        $request = $this->baseRequest->withUploadedFiles($files);
        
        $this->assertInstanceof(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertSame($files, $request->getUploadedFiles());
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage 'colors[red]' is not an UploadedFileInterface object, but a string
     */
    public function testWithUploadedFilesInvalidStructure()
    {
        $file = $this->createMock(UploadedFile::class);
        $blue = clone $file;
        $red = 'hello';
        
        $this->baseRequest->withUploadedFiles(['file' => $file, 'colors' => compact('blue', 'red')]);
    }

    public function testWithUploadedFilesTurnStale()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $refl->setAccessible(true);
        $refl->setValue($this->baseRequest, false);
        
        $request = $this->baseRequest->withUploadedFiles([]);
        
        $this->assertTrue($this->baseRequest->isStale());
        $this->assertFalse($request->isStale());
    }
    
    protected function createMockUploadedFile($name, $type, $size, $tmp_name, $error = UPLOAD_ERR_OK)
    {
        $file = $this->createMock(UploadedFile::class);
        $file->expects($this->atLeastOnce())->method('getClientFilename')->willReturn($name);
        $file->expects($this->atLeastOnce())->method('getClientMediaType')->willReturn($type);
        $file->expects($this->atLeastOnce())->method('getSize')->willReturn($size);
        $file->expects($this->atLeastOnce())->method('getError')->willReturn($error);

        if ($tmp_name) {
            $fileStream = $this->createMock(Stream::class);
            $fileStream->expects($this->atLeastOnce())->method('getMetadata')->with('url')->willReturn($tmp_name);
            
            $file->expects($this->atLeastOnce())->method('getStream')->willReturn($fileStream);
        } else {
            $exception = new \RuntimeException();
            $file->expects($this->atLeastOnce())->method('getStream')->willThrowException($exception);
        }
        
        return $file;
    }
    
    public function testUngroupUploadedFiles()
    {
        $keys = ['name', 'type', 'size', 'tmp_name', 'error'];
        
        $files = [
            'qux' => array_combine($keys, ['qux.txt', 'text/plain', 3, '/tmp/xx', UPLOAD_ERR_OK]),
        ];
        
        $refl = new \ReflectionMethod(ServerRequest::class, 'setUploadedFiles');
        $refl->setAccessible(true);
        $refl->invokeArgs($this->baseRequest, [&$files]);
        
        
        $file = $this->createMockUploadedFile('foo.txt', 'text/plain', 3, '/tmp/qq');
        $more = $this->createMockUploadedFile(null, null, null, null, UPLOAD_ERR_NO_FILE);
        
        $colors['red'] = $this->createMockUploadedFile('cherry.html', 'text/html', 15, '/tmp/ab');
        $colors['blue'] = $this->createMockUploadedFile('navy.txt', 'text/plain', 4, '/tmp/cd');
        $colors['green'] = $this->createMockUploadedFile('apple.jpg', 'image/jpeg', null, null, UPLOAD_ERR_INI_SIZE);
        $colors['other'][] = $this->createMockUploadedFile('yellow.txt', 'text/plain', 6, '/tmp/gh');
        $colors['other'][] = $this->createMockUploadedFile('purple.txt', 'text/plain', 36, '/tmp/ij');
        
        $request = $this->baseRequest->withUploadedFiles(compact('file', 'more', 'colors'));
        
        $colorKeys = array_keys($colors);
        
        $this->assertEquals([
            'file' => array_combine($keys, ['foo.txt', 'text/plain', 3, '/tmp/qq', UPLOAD_ERR_OK]),
            'more' => array_combine($keys, [null, null, null, null, UPLOAD_ERR_NO_FILE]),
            'colors' => array_combine($keys, [
                array_combine($colorKeys, ['cherry.html', 'navy.txt', 'apple.jpg', ['yellow.txt', 'purple.txt']]),
                array_combine($colorKeys, ['text/html', 'text/plain', 'image/jpeg', ['text/plain', 'text/plain']]),
                array_combine($colorKeys, [15, 4, null, [6, 36]]),
                array_combine($colorKeys, ['/tmp/ab', '/tmp/cd', null, ['/tmp/gh', '/tmp/ij']]),
                array_combine($colorKeys, [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE,
                    [UPLOAD_ERR_OK, UPLOAD_ERR_OK]]),
            ])
        ], $files);
    }


    public function testGetParsedBodyDefault()
    {
        $this->assertNull($this->baseRequest->getParsedBody());
    }

    public function testParseUrlEncodedBody()
    {
        $this->setContentType('application/x-www-form-urlencoded');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('foo=bar&color=red');
        
        $request = $this->baseRequest->withBody($body);
        
        $this->assertEquals(['foo' => 'bar', 'color' => 'red'], $request->getParsedBody());
        
        return $request;
    }

    /**
     * @expectedException RuntimeException
     * @expectedExceptionMessage Parsing multipart/form-data isn't supported
     */
    public function testParseMultipartBody()
    {
        $this->setContentType('multipart/form-data');
        $this->baseRequest->getParsedBody();
    }

    public function testParseJsonBody()
    {
        $this->setContentType('application/json');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('{"foo":"bar","color":"red"}');
        
        $request = $this->baseRequest->withBody($body);
        
        $this->assertEquals(['foo' => 'bar', 'color' => 'red'], $request->getParsedBody());
    }

    public function testParseInvalidJsonBody()
    {
        $this->setContentType('application/json');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('not json');
        
        $request = $this->baseRequest->withBody($body);
        
        $this->assertNull(@$request->getParsedBody());
        $this->assertLastError(E_USER_WARNING, 'Failed to parse json body: Syntax error');
    }

    public function testParseXmlBody()
    {
        if (!function_exists('simplexml_load_string')) {
            return $this->markTestSkipped('SimpleXML extension not loaded');
        }

        $this->setContentType('text/xml');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('<foo>bar</foo>');
        
        $request = $this->baseRequest->withBody($body);
        $parsedBody = $request->getParsedBody();
        
        $this->assertInstanceOf(\SimpleXMLElement::class, $parsedBody);
        $this->assertXmlStringEqualsXmlString('<foo>bar</foo>', $parsedBody->asXML());
    }

    public function testParseInvalidXmlBody()
    {
        $this->setContentType('text/xml');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('not xml');
        
        $request = $this->baseRequest->withBody($body);
        
        $this->assertNull(@$request->getParsedBody());
        $this->assertLastError(E_WARNING);
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage Unable to parse body: 'Content-Type' header is missing
     */
    public function testParseUnknownBody()
    {
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('getSize')
            ->willReturn(4);
        
        $request = $this->baseRequest->withBody($body);
        $request->getParsedBody();
    }

    public function testParseUnsupportedBody()
    {
        $this->setContentType('application/x-foo');
        
        $this->assertNull($this->baseRequest->getParsedBody());
    }

    /**
     * @depends testParseUrlEncodedBody
     */
    public function testResetParsedBody(ServerRequest $originalRequest)
    {
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('foo=do&color=blue'); // Same size
        

        $request = $originalRequest->withBody($body);
        $this->assertEquals(['foo' => 'do', 'color' => 'blue'], $request->getParsedBody());
    }

    /**
     * ServerRequest::setPostData is protected, because it should only be used for $_POST
     */
    public function testSetPostDataVsJsonContent()
    {
        $data = [];
        
        $this->setContentType('application/json');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->once())
            ->method('__toString')
            ->willReturn('{"foo": "bar"}');
        
        $refl = new \ReflectionMethod(ServerRequest::class, 'setPostData');
        $refl->setAccessible(true);
        
        $request = $this->baseRequest->withBody($body);
        
        $refl->invokeArgs($request, [&$data]); // Should have no effect
        $this->assertEquals(['foo' => 'bar'], $request->getParsedBody());
        
        $refl->invokeArgs($request, [&$data]); // Should still have no effect
        $this->assertEquals(['foo' => 'bar'], $request->getParsedBody());
    }

    public function testWithParsedBody()
    {
        $request = $this->baseRequest->withParsedBody(['foo' => 'bar']);
        
        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals(['foo' => 'bar'], $request->getParsedBody());
    }

    public function testWithParsedBodyNoReset()
    {
        $body = $this->createMock(Stream::class);
        $body->expects($this->never())
            ->method('__toString');
        
        $body->expects($this->never())
            ->method('getSize');
        
        $request = $this->baseRequest->withBody($body)->withParsedBody(['foo' => 'bar']);
        
        $this->assertEquals(['foo' => 'bar'], $request->getParsedBody());
    }

    public function testWithParsedBodyTurnStale()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'isStale');
        $refl->setAccessible(true);
        $refl->setValue($this->baseRequest, false);
        
        $request = $this->baseRequest->withParsedBody([]);
        
        $this->assertTrue($this->baseRequest->isStale());
        $this->assertFalse($request->isStale());
    }

    /**
     * @expectedException PHPUnit_Framework_Error_Warning
     * @expectedExceptionMessage Failed to parse json body: Syntax error
     */
    public function testReparseBodyOnContentType()
    {
        $contentType = 'application/x-www-form-urlencoded';
        
        $this->headers->expects($this->any())
            ->method('getHeaderLine')
            ->will($this->returnCallback(function () use (&$contentType) { return $contentType; }));
        
        $this->headers->expects($this->once())
            ->method('withHeader')
            ->with('Content-Type', 'application/json')
            ->will($this->returnSelf());

        $body = $this->createMock(Stream::class);
        $body->expects($this->exactly(2))
            ->method('__toString')
            ->willReturn('foo=bar&color=red');

        $request = $this->baseRequest->withBody($body);

        $request->getParsedBody();
        
        $contentType = 'application/json';
        $request->withHeader('Content-Type', $contentType)->getParsedBody();
    }

    public function testReparseBodyOnSize()
    {
        $this->setContentType('application/x-www-form-urlencoded');
        
        $body = $this->createMock(Stream::class);
        $body->expects($this->exactly(2))
            ->method('__toString')
            ->willReturnOnConsecutiveCalls('foo=bar', 'foo=bar&color=red');
        
        $body->expects($this->exactly(4))
            ->method('getSize')
            ->willReturnOnConsecutiveCalls(7, 17, 17, 17);
        
        $request = $this->baseRequest->withBody($body);
        
        $this->assertEquals(['foo' => 'bar'], $request->getParsedBody());
        
        // Second call with appended content for body
        $this->assertEquals(['foo' => 'bar', 'color' => 'red'], $request->getParsedBody());
        
        // Third call with no reparse
        $this->assertEquals(['foo' => 'bar', 'color' => 'red'], $request->getParsedBody());
    }

    /**
     * `ServerRequest::createDerivedAttributes` is protected, but we don't want to execute the derived attributes
     */
    public function testCreateDerivedAttributes()
    {
        $refl = new \ReflectionProperty(ServerRequest::class, 'attributes');
        $refl->setAccessible(true);
        
        $attributes = $refl->getValue($this->baseRequest);
        
        $this->assertArrayHasKey('client_ip', $attributes);
        $this->assertInstanceOf(DerivedAttribute\ClientIp::class, $attributes['client_ip']);
        
        $this->assertArrayHasKey('is_xhr', $attributes);
        $this->assertInstanceOf(DerivedAttribute\IsXhr::class, $attributes['is_xhr']);
        
        $this->assertArrayHasKey('local_referer', $attributes);
        $this->assertInstanceOf(DerivedAttribute\LocalReferer::class, $attributes['local_referer']);
    }

    public function testWithAttribute()
    {
        $request = $this->baseRequest->withAttribute('foo', ['bar', 'zoo']);
        
        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertEquals(['bar', 'zoo'], $request->getAttribute('foo'));
        
        return $request;
    }

    /**
     * @depends testWithAttribute
     */
    public function testWithAttributeOverride(ServerRequest $originalRequest)
    {
        $request = $originalRequest->withAttribute('foo', 'black');
        $this->assertEquals('black', $request->getAttribute('foo'));
    }

    public function testWithAttributeAsCallback()
    {
        $request = null;
        
        $request = $this->baseRequest->withAttribute('foo', function ($arg) use (&$request) {
            $this->assertSame($request, $arg);
            return ['bar', 'zoo'];
        });
        
        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertEquals(['bar', 'zoo'], $request->getAttribute('foo'));
    }

    public function testWithAttributeAsObject()
    {
        $attribute = $this->getMockBuilder(DerivedAttribute::class)->getMock();
        $request = $this->baseRequest->withAttribute('foo', $attribute);
        
        $attribute->expects($this->once())
            ->method('__invoke')
            ->with($this->identicalTo($request))
            ->willReturn(['bar', 'zoo']);
        
        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertEquals(['bar', 'zoo'], $request->getAttribute('foo'));
    }

    /**
     * @depends testWithAttribute
     */
    public function testWithoutAttribute(ServerRequest $originalRequest)
    {
        $request = $originalRequest->withoutAttribute('foo');
        
        $this->assertInstanceOf(ServerRequest::class, $request);
        $this->assertNotSame($this->baseRequest, $request);
        
        $this->assertNull($request->getAttribute('foo'));
    }

    public function testGetAttributes()
    {
        // Remove all attributes, as we don't want to them
        $refl = new \ReflectionProperty(ServerRequest::class, 'attributes');
        $refl->setAccessible(true);
        $refl->setValue($this->baseRequest, []);
        
        $request = $this->baseRequest->withAttribute('foo', function () {
            return ['bar', 'zoo'];
        })->withAttribute('color', 'red');
        
        $this->assertEquals(['foo' => ['bar', 'zoo'], 'color' => 'red'], $request->getAttributes());
    }
}
