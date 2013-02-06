<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfullyTest;

use PhlyRestfully\Exception;
use PhlyRestfully\Plugin;
use PhlyRestfully\Resource;
use PhlyRestfully\ResourceController;
use PHPUnit_Framework_TestCase as TestCase;
use ReflectionObject;
use stdClass;
use Zend\EventManager\EventManager;
use Zend\EventManager\SharedEventManager;
use Zend\Mvc\Controller\PluginManager;
use Zend\Mvc\Controller\Plugin\Url as UrlHelper;
use Zend\Mvc\MvcEvent;
use Zend\Mvc\Router\Http\Segment;
use Zend\Mvc\Router\RouteMatch;
use Zend\Mvc\Router\SimpleRouteStack;
use Zend\Paginator\Adapter\ArrayAdapter as ArrayPaginator;
use Zend\Paginator\Paginator;
use Zend\Stdlib\Parameters;
use Zend\View\Helper\ServerUrl as ServerUrlHelper;

/**
 * @subpackage UnitTest
 */
class ResourceControllerTest extends TestCase
{
    public function setUp()
    {
        $this->controller = $controller = new ResourceController();

        $this->router = $router = new SimpleRouteStack();
        $route = new Segment('/resource[/[:id]]');
        $router->addRoute('resource', $route);
        $this->event = $event = new MvcEvent();
        $event->setRouter($router);
        $event->setRouteMatch(new RouteMatch(array()));
        $controller->setEvent($event);
        $controller->setRoute('resource');

        $pluginManager = new PluginManager();
        $controller->setPluginManager($pluginManager);
        $urlHelper = new UrlHelper();
        $urlHelper->setController($controller);
        $pluginManager->setService('url', $urlHelper);

        $serverUrlHelper = new ServerUrlHelper();
        $serverUrlHelper->setScheme('http');
        $serverUrlHelper->setHost('localhost.localdomain');

        $linksHelper = new Plugin\Links();
        $linksHelper->setUrlHelper($urlHelper);
        $linksHelper->setServerUrlHelper($serverUrlHelper);
        $pluginManager->setService('links', $linksHelper);
        $linksHelper->setController($controller);

        $apiProblemHelper = new Plugin\ApiProblemResult();
        $pluginManager->setService('apiproblemresult', $apiProblemHelper);
        $apiProblemHelper->setController($controller);

        $this->resource = $resource = new Resource();
        $controller->setResource($resource);
    }

    public function assertProblemApiResult($expectedHttpStatus, $expectedDetail, $result)
    {
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('httpStatus', $result);
        $this->assertEquals($expectedHttpStatus, $result['httpStatus']);
        $this->assertArrayHasKey('detail', $result);
        $this->assertContains($expectedDetail, $result['detail']);
    }

    public function testCreateReturnsProblemResultOnCreationException()
    {
        $this->resource->getEventManager()->attach('create', function ($e) {
            throw new Exception\CreationException('failed');
        });

        $result = $this->controller->create(array());
        $this->assertProblemApiResult(500, 'failed', $result);
    }

    public function testCreateReturnsProblemResultOnBadItemIdentifier()
    {
        $this->resource->getEventManager()->attach('create', function ($e) {
            return array('foo' => 'bar');
        });

        $result = $this->controller->create(array());
        $this->assertProblemApiResult(422, 'item identifier', $result);
    }

    public function testCreateReturnsHalArrayOnSuccess()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('create', function ($e) use ($item) {
            return $item;
        });

        $result = $this->controller->create(array());
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertRegexp('#/resource$#', $result['_links']['up']['href']);
        $this->assertRegexp('#/resource/foo$#', $result['_links']['self']['href']);
        $this->assertArrayHasKey('item', $result);
        $this->assertEquals($item, $result['item']);
    }

    public function testFalseFromDeleteResourceReturnsProblemApiResult()
    {
        $this->resource->getEventManager()->attach('delete', function ($e) {
            return false;
        });

        $result = $this->controller->delete('foo');
        $this->assertProblemApiResult(422, 'delete', $result);
    }

    public function testTrueFromDeleteResourceReturnsResponseWithNoContent()
    {
        $this->resource->getEventManager()->attach('delete', function ($e) {
            return true;
        });

        $result = $this->controller->delete('foo');
        $this->assertInstanceOf('Zend\Http\Response', $result);
        $this->assertEquals(204, $result->getStatusCode());
    }

    public function testReturningEmptyResultFromGetReturnsProblemApiResult()
    {
        $this->resource->getEventManager()->attach('fetch', function ($e) {
            return false;
        });

        $result = $this->controller->get('foo');
        $this->assertProblemApiResult(404, 'not found', $result);
    }

    public function testReturningItemFromGetReturnsExpectedHalResult()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('fetch', function ($e) use ($item) {
            return $item;
        });

        $result = $this->controller->get('foo');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertRegexp('#/resource$#', $result['_links']['up']['href']);
        $this->assertRegexp('#/resource/foo$#', $result['_links']['self']['href']);
        $this->assertArrayHasKey('item', $result);
        $this->assertEquals($item, $result['item']);
    }

    public function testReturnsHalResponseWithOnlySelfReferenceForNonPaginatedList()
    {
        $items = array(
            array('id' => 'foo', 'bar' => 'baz')
        );
        $this->resource->getEventManager()->attach('fetchAll', function ($e) use ($items) {
            return $items;
        });

        $result = $this->controller->getList();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertRegexp('#/resource$#', $result['_links']['self']['href']);
        $this->assertArrayHasKey('items', $result);

        $item = $items[0];
        $test = $result['items'][0];
        $this->assertEquals($item, $test['item']);
        $this->assertRegexp('#/resource/foo$#', $test['_links']['self']['href']);
    }

    public function testReturnsHalResponseForPaginatedList()
    {
        $items = array(
            array('id' => 'foo', 'bar' => 'baz'),
            array('id' => 'bar', 'bar' => 'baz'),
            array('id' => 'baz', 'bar' => 'baz'),
        );
        $adapter   = new ArrayPaginator($items);
        $paginator = new Paginator($adapter);
        $this->resource->getEventManager()->attach('fetchAll', function ($e) use ($paginator) {
            return $paginator;
        });

        $this->controller->setPageSize(1);
        $request = $this->controller->getRequest();
        $request->setQuery(new Parameters(array('page' => 2)));

        $result = $this->controller->getList();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertRegexp('#/resource\?page\=2$#', $result['_links']['self']['href']);
        $this->assertRegexp('#/resource$#', $result['_links']['first']['href']);
        $this->assertRegexp('#/resource\?page\=3$#', $result['_links']['last']['href']);
        $this->assertRegexp('#/resource$#', $result['_links']['prev']['href']);
        $this->assertRegexp('#/resource\?page\=3$#', $result['_links']['next']['href']);

        $this->assertArrayHasKey('items', $result);
        $item = $items[1];
        $test = $result['items'][0];
        $this->assertEquals($item, $test['item']);
        $this->assertRegexp('#/resource/bar$#', $test['_links']['self']['href']);
        $this->assertEquals($item, $test['item']);
    }

    public function testHeadReturnsListResponseWhenNoIdProvided()
    {
        $items = array(
            array('id' => 'foo', 'bar' => 'baz'),
            array('id' => 'bar', 'bar' => 'baz'),
            array('id' => 'baz', 'bar' => 'baz'),
        );
        $adapter   = new ArrayPaginator($items);
        $paginator = new Paginator($adapter);
        $this->resource->getEventManager()->attach('fetchAll', function ($e) use ($paginator) {
            return $paginator;
        });

        $this->controller->setPageSize(1);
        $request = $this->controller->getRequest();
        $request->setQuery(new Parameters(array('page' => 2)));

        $result = $this->controller->head();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertArrayHasKey('items', $result);
    }

    public function testHeadReturnsItemResponseWhenIdProvided()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('fetch', function ($e) use ($item) {
            return $item;
        });

        $result = $this->controller->head('foo');
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertArrayHasKey('item', $result);
    }

    public function testOptionsReturnsEmptyResponseWithAllowHeaderPopulatedForResource()
    {
        $r = new ReflectionObject($this->controller);
        $httpOptionsProp = $r->getProperty('resourceHttpOptions');
        $httpOptionsProp->setAccessible(true);
        $httpOptions = $httpOptionsProp->getValue($this->controller);
        sort($httpOptions);

        $result = $this->controller->options();
        $this->assertInstanceOf('Zend\Http\Response', $result);
        $this->assertEquals(204, $result->getStatusCode());
        $headers = $result->getHeaders();
        $this->assertTrue($headers->has('allow'));
        $allow = $headers->get('allow');
        $test  = $allow->getFieldValue();
        $test  = explode(', ', $test);
        sort($test);
        $this->assertEquals($httpOptions, $test);
    }

    public function testOptionsReturnsEmptyResponseWithAllowHeaderPopulatedForItem()
    {
        $r = new ReflectionObject($this->controller);
        $httpOptionsProp = $r->getProperty('itemHttpOptions');
        $httpOptionsProp->setAccessible(true);
        $httpOptions = $httpOptionsProp->getValue($this->controller);
        sort($httpOptions);

        $this->event->getRouteMatch()->setParam('id', 'foo');

        $result = $this->controller->options();
        $this->assertInstanceOf('Zend\Http\Response', $result);
        $this->assertEquals(204, $result->getStatusCode());
        $headers = $result->getHeaders();
        $this->assertTrue($headers->has('allow'));
        $allow = $headers->get('allow');
        $test  = $allow->getFieldValue();
        $test  = explode(', ', $test);
        sort($test);
        $this->assertEquals($httpOptions, $test);
    }


    public function testPatchReturnsProblemResultOnPatchException()
    {
        $this->resource->getEventManager()->attach('patch', function ($e) {
            throw new Exception\PatchException('failed');
        });

        $result = $this->controller->patch('foo', array());
        $this->assertProblemApiResult(500, 'failed', $result);
    }

    public function testPatchReturnsHalArrayOnSuccess()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('patch', function ($e) use ($item) {
            return $item;
        });

        $result = $this->controller->patch('foo', $item);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertRegexp('#/resource$#', $result['_links']['up']['href']);
        $this->assertRegexp('#/resource/foo$#', $result['_links']['self']['href']);
        $this->assertArrayHasKey('item', $result);
        $this->assertEquals($item, $result['item']);
    }

    public function testUpdateReturnsProblemResultOnUpdateException()
    {
        $this->resource->getEventManager()->attach('update', function ($e) {
            throw new Exception\UpdateException('failed');
        });

        $result = $this->controller->update('foo', array());
        $this->assertProblemApiResult(500, 'failed', $result);
    }

    public function testUpdateReturnsHalArrayOnSuccess()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('update', function ($e) use ($item) {
            return $item;
        });

        $result = $this->controller->update('foo', $item);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertRegexp('#/resource$#', $result['_links']['up']['href']);
        $this->assertRegexp('#/resource/foo$#', $result['_links']['self']['href']);
        $this->assertArrayHasKey('item', $result);
        $this->assertEquals($item, $result['item']);
    }

    public function testReplaceListReturnsProblemResultOnUpdateException()
    {
        $this->resource->getEventManager()->attach('replaceList', function ($e) {
            throw new Exception\UpdateException('failed');
        });

        $result = $this->controller->replaceList(array());
        $this->assertProblemApiResult(500, 'failed', $result);
    }

    public function testReplaceListReturnsHalArrayOnSuccess()
    {
        $items = array(array('id' => 'foo1', 'bar' => 'baz1'), array('id' => 'foo2', 'bar' => 'baz2'));
        $this->resource->getEventManager()->attach('replaceList', function ($e) use ($items) {
            return $items;
        });

        $result = $this->controller->replaceList($items);
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('_links', $result);
        $this->assertInternalType('array', $result['_links']);
        $this->assertArrayHasKey('items', $result);
        foreach ($items as $key => $item) {
            $this->assertEquals($item, $result['items'][$key]['item']);
        }
    }

    public function testOnDispatchRaisesDomainExceptionOnMissingResource()
    {
        $controller = new ResourceController();
        $this->setExpectedException('PhlyRestfully\Exception\DomainException', 'ResourceInterface');
        $controller->onDispatch($this->event);
    }

    public function testOnDispatchRaisesDomainExceptionOnMissingRoute()
    {
        $controller = new ResourceController();
        $controller->setResource($this->resource);
        $this->setExpectedException('PhlyRestfully\Exception\DomainException', 'route');
        $controller->onDispatch($this->event);
    }

    public function testOnDispatchReturns405ResponseForInvalidResourceMethod()
    {
        $this->controller->setResourceHttpOptions(array('GET'));
        $request = $this->controller->getRequest();
        $request->setMethod('POST');
        $this->event->setRequest($request);
        $this->event->setResponse($this->controller->getResponse());

        $result = $this->controller->onDispatch($this->event);
        $this->assertInstanceOf('Zend\Http\Response', $result);
        $this->assertEquals(405, $result->getStatusCode());
        $headers = $result->getHeaders();
        $this->assertTrue($headers->has('allow'));
        $allow = $headers->get('allow');
        $this->assertEquals('GET', $allow->getFieldValue());
    }

    public function testOnDispatchReturns405ResponseForInvalidItemMethod()
    {
        $this->controller->setItemHttpOptions(array('GET'));
        $request = $this->controller->getRequest();
        $request->setMethod('PUT');
        $this->event->setRequest($request);
        $this->event->setResponse($this->controller->getResponse());
        $this->event->getRouteMatch()->setParam('id', 'foo');

        $result = $this->controller->onDispatch($this->event);
        $this->assertInstanceOf('Zend\Http\Response', $result);
        $this->assertEquals(405, $result->getStatusCode());
        $headers = $result->getHeaders();
        $this->assertTrue($headers->has('allow'));
        $allow = $headers->get('allow');
        $this->assertEquals('GET', $allow->getFieldValue());
    }

    public function testValidMethodReturningArrayCastsReturnToViewModel()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('fetch', function ($e) use ($item) {
            return $item;
        });

        $this->controller->setItemHttpOptions(array('GET'));

        $request = $this->controller->getRequest();
        $request->setMethod('GET');
        $this->event->setRequest($request);
        $this->event->getRouteMatch()->setParam('id', 'foo');

        $result = $this->controller->onDispatch($this->event);
        $this->assertInstanceof('Zend\View\Model\ModelInterface', $result);
    }

    public function testValidMethodReturningArrayCastsReturnToRestfulJsonModelWhenAcceptHeaderIsJson()
    {
        $item = array('id' => 'foo', 'bar' => 'baz');
        $this->resource->getEventManager()->attach('fetch', function ($e) use ($item) {
            return $item;
        });

        $this->controller->setItemHttpOptions(array('GET'));

        $request = $this->controller->getRequest();
        $request->setMethod('GET');
        $request->getHeaders()->addHeaderLine('Accept', 'application/json');
        $this->event->setRequest($request);
        $this->event->getRouteMatch()->setParam('id', 'foo');

        $result = $this->controller->onDispatch($this->event);
        $this->assertInstanceof('PhlyRestfully\RestfulJsonModel', $result);
    }

    public function testPassingIdentifierToConstructorAllowsListeningOnThatIdentifier()
    {
        $controller   = new ResourceController('MyNamespace\Controller\Foo');
        $events       = new EventManager();
        $sharedEvents = new SharedEventManager();
        $events->setSharedManager($sharedEvents);
        $controller->setEventManager($events);

        $test = new stdClass;
        $test->flag = false;
        $sharedEvents->attach('MyNamespace\Controller\Foo', 'test', function ($e) use ($test) {
            $test->flag = true;
        });

        $events->trigger('test', $controller, array());
        $this->assertTrue($test->flag);
    }
}
