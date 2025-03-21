<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_cache;

use cache;
use cache_application;
use cache_config;
use cache_config_disabled;
use cache_config_testing;
use cache_definition;
use cache_disabled;
use cache_factory;
use cache_factory_disabled;
use cache_helper;
use cache_loader;
use cache_phpunit_application;
use cache_phpunit_cache;
use cache_phpunit_dummy_object;
use cache_phpunit_dummy_overrideclass;
use cache_phpunit_factory;
use cache_phpunit_request;
use cache_phpunit_session;
use cache_request;
use cache_session;
use cache_store;
use cacheable_object_array;

/**
 * PHPunit tests for the cache API
 *
 * This file is part of Moodle's cache API, affectionately called MUC.
 * It contains the components that are requried in order to use caching.
 *
 * @package    core
 * @category   cache
 * @copyright  2012 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \cache
 * @covers \cache
 */
final class cache_test extends \advanced_testcase {
    /**
     * Load required libraries and fixtures.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/cache/locallib.php');
        require_once($CFG->dirroot . '/cache/tests/fixtures/lib.php');
        require_once($CFG->dirroot . '/cache/tests/fixtures/cache_phpunit_dummy_datasource_versionable.php');
    }

    /**
     * Set things back to the default before each test.
     */
    public function setUp(): void {
        parent::setUp();
        cache_factory::reset();
        cache_config_testing::create_default_configuration();
    }

    /**
     * Final task is to reset the cache system
     */
    public static function tearDownAfterClass(): void {
        parent::tearDownAfterClass();
        cache_factory::reset();
    }

    /**
     * Returns the expected application cache store.
     * @return string
     */
    protected function get_expected_application_cache_store() {
        global $CFG;
        $expected = 'cachestore_file';

        // Verify if we are using any of the available ways to use a different application store within tests.
        if (defined('TEST_CACHE_USING_APPLICATION_STORE') && preg_match('#[a-zA-Z][a-zA-Z0-9_]*#', TEST_CACHE_USING_APPLICATION_STORE)) {
            // 1st way. Using some of the testing servers.
            $expected = 'cachestore_'.(string)TEST_CACHE_USING_APPLICATION_STORE;

        } else if (defined('TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH') && TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH && !empty($CFG->altcacheconfigpath)) {
            // 2nd way. Using an alternative configuration.
            $defaultstores = cache_helper::get_stores_suitable_for_mode_default();
            $instance = cache_config::instance();
            // Iterate over defined mode mappings until we get an application one not being the default.
            foreach ($instance->get_mode_mappings() as $mapping) {
                // If the store is not for application mode, ignore.
                if ($mapping['mode'] !== cache_store::MODE_APPLICATION) {
                    continue;
                }
                // If the store matches some default mapping store name, ignore.
                if (array_key_exists($mapping['store'], $defaultstores) && !empty($defaultstores[$mapping['store']]['default'])) {
                    continue;
                }
                // Arrived here, have found an application mode store not being the default mapped one (file),
                // that's the one we are using in the configuration for sure.
                $expected = 'cachestore_'.$mapping['store'];
            }
        }

        return $expected;
    }

    /**
     * Tests cache configuration
     */
    public function test_cache_config(): void {
        global $CFG;

        if (defined('TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH') && TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH &&
            !empty($CFG->altcacheconfigpath)) {
            // We need to skip this test - it checks the default config structure, but very likely we arn't using the
            // default config structure here so theres no point in running the test.
            $this->markTestSkipped('Skipped testing default cache config structure as alt cache path is being used.');
        }

        if (defined('TEST_CACHE_USING_APPLICATION_STORE')) {
            // We need to skip this test - it checks the default config structure, but very likely we arn't using the
            // default config structure here because we are testing against an alternative application store.
            $this->markTestSkipped('Skipped testing default cache config structure as alt application store is being used.');
        }

        $instance = cache_config::instance();
        $this->assertInstanceOf(cache_config_testing::class, $instance);

        $this->assertTrue(cache_config_testing::config_file_exists());

        $stores = $instance->get_all_stores();
        $this->assertCount(3, $stores);
        foreach ($stores as $name => $store) {
            // Check its an array.
            $this->assertIsArray($store);
            // Check the name is the key.
            $this->assertEquals($name, $store['name']);
            // Check that it has been declared default.
            $this->assertTrue($store['default']);
            // Required attributes = name + plugin + configuration + modes + features.
            $this->assertArrayHasKey('name', $store);
            $this->assertArrayHasKey('plugin', $store);
            $this->assertArrayHasKey('configuration', $store);
            $this->assertArrayHasKey('modes', $store);
            $this->assertArrayHasKey('features', $store);
        }

        $modemappings = $instance->get_mode_mappings();
        $this->assertCount(3, $modemappings);
        $modes = array(
            cache_store::MODE_APPLICATION => false,
            cache_store::MODE_SESSION => false,
            cache_store::MODE_REQUEST => false,
        );
        foreach ($modemappings as $mapping) {
            // We expect 3 properties.
            $this->assertCount(3, $mapping);
            // Required attributes = mode + store.
            $this->assertArrayHasKey('mode', $mapping);
            $this->assertArrayHasKey('store', $mapping);
            // Record the mode.
            $modes[$mapping['mode']] = true;
        }

        // Must have the default 3 modes and no more.
        $this->assertCount(3, $mapping);
        foreach ($modes as $mode) {
            $this->assertTrue($mode);
        }

        $definitions = $instance->get_definitions();
        // The event invalidation definition is required for the cache API and must be there.
        $this->assertArrayHasKey('core/eventinvalidation', $definitions);

        $definitionmappings = $instance->get_definition_mappings();
        foreach ($definitionmappings as $mapping) {
            // Required attributes = definition + store.
            $this->assertArrayHasKey('definition', $mapping);
            $this->assertArrayHasKey('store', $mapping);
        }
    }

    /**
     * Tests for cache keys that would break on windows.
     */
    public function test_windows_nasty_keys(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/windowskeytest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'windowskeytest',
            'simplekeys' => true,
            'simpledata' => true
        ));
        $cache = cache::make('phpunit', 'windowskeytest');
        $this->assertTrue($cache->set('contest', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('contest'));
    }

    /**
     * Tests set_identifiers fails post cache creation.
     *
     * set_identifiers cannot be called after initial cache instantiation, as you need to create a difference cache.
     */
    public function test_set_identifiers(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/identifier', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'identifier',
            'simplekeys' => true,
            'simpledata' => true,
            'staticacceleration' => true
        ));
        $cache = cache::make('phpunit', 'identifier', array('area'));
        $this->assertTrue($cache->set('contest', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('contest'));

        $this->expectException('coding_exception');
        $cache->set_identifiers(array());
    }

    /**
     * Tests the default application cache
     */
    public function test_default_application_cache(): void {
        $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'phpunit', 'applicationtest');
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->run_on_cache($cache);

        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/test_default_application_cache', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'test_default_application_cache',
            'staticacceleration' => true,
            'staticaccelerationsize' => 1
        ));
        $cache = cache::make('phpunit', 'test_default_application_cache');
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->run_on_cache($cache);
    }

    /**
     * Tests the default session cache
     */
    public function test_default_session_cache(): void {
        $cache = cache::make_from_params(cache_store::MODE_SESSION, 'phpunit', 'applicationtest');
        $this->assertInstanceOf(cache_session::class, $cache);
        $this->run_on_cache($cache);
    }

    /**
     * Tests the default request cache
     */
    public function test_default_request_cache(): void {
        $cache = cache::make_from_params(cache_store::MODE_REQUEST, 'phpunit', 'applicationtest');
        $this->assertInstanceOf(cache_request::class, $cache);
        $this->run_on_cache($cache);
    }

    /**
     * Tests using a cache system when there are no stores available (who knows what the admin did to achieve this).
     */
    public function test_on_cache_without_store(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/nostoretest1', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'nostoretest1',
        ));
        $instance->phpunit_add_definition('phpunit/nostoretest2', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'nostoretest2',
            'staticacceleration' => true
        ));
        $instance->phpunit_remove_stores();

        $cache = cache::make('phpunit', 'nostoretest1');
        $this->run_on_cache($cache);

        $cache = cache::make('phpunit', 'nostoretest2');
        $this->run_on_cache($cache);
    }

    /**
     * Runs a standard series of access and use tests on a cache instance.
     *
     * This function is great because we can use it to ensure all of the loaders perform exactly the same way.
     *
     * @param cache_loader $cache
     */
    protected function run_on_cache(cache_loader $cache) {
        $key = 'contestkey';
        $datascalars = array('test data', null);
        $dataarray = array('contest' => 'data', 'part' => 'two');
        $dataobject = (object)$dataarray;

        foreach ($datascalars as $datascalar) {
            $this->assertTrue($cache->purge());

            // Check all read methods.
            $this->assertFalse($cache->get($key));
            $this->assertFalse($cache->has($key));
            $result = $cache->get_many(array($key));
            $this->assertCount(1, $result);
            $this->assertFalse(reset($result));
            $this->assertFalse($cache->has_any(array($key)));
            $this->assertFalse($cache->has_all(array($key)));

            // Set the data.
            $this->assertTrue($cache->set($key, $datascalar));
            // Setting it more than once should be permitted.
            $this->assertTrue($cache->set($key, $datascalar));

            // Recheck the read methods.
            $this->assertEquals($datascalar, $cache->get($key));
            $this->assertTrue($cache->has($key));
            $result = $cache->get_many(array($key));
            $this->assertCount(1, $result);
            $this->assertEquals($datascalar, reset($result));
            $this->assertTrue($cache->has_any(array($key)));
            $this->assertTrue($cache->has_all(array($key)));

            // Delete it.
            $this->assertTrue($cache->delete($key));

            // Check its gone.
            $this->assertFalse($cache->get($key));
            $this->assertFalse($cache->has($key));
        }

        // Test arrays.
        $this->assertTrue($cache->set($key, $dataarray));
        $this->assertEquals($dataarray, $cache->get($key));

        // Test objects.
        $this->assertTrue($cache->set($key, $dataobject));
        $this->assertEquals($dataobject, $cache->get($key));

        $starttime = microtime(true);
        $specobject = new cache_phpunit_dummy_object('red', 'blue', $starttime);
        $this->assertTrue($cache->set($key, $specobject));
        $result = $cache->get($key);
        $this->assertInstanceOf(cache_phpunit_dummy_object::class, $result);
        $this->assertEquals('red_ptc_wfc', $result->property1);
        $this->assertEquals('blue_ptc_wfc', $result->property2);
        $this->assertGreaterThan($starttime, $result->propertytime);

        // Test array of objects.
        $specobject = new cache_phpunit_dummy_object('red', 'blue', $starttime);
        $data = new cacheable_object_array(array(
            clone($specobject),
            clone($specobject),
            clone($specobject))
        );
        $this->assertTrue($cache->set($key, $data));
        $result = $cache->get($key);
        $this->assertInstanceOf(cacheable_object_array::class, $result);
        $this->assertCount(3, $data);
        foreach ($result as $item) {
            $this->assertInstanceOf(cache_phpunit_dummy_object::class, $item);
            $this->assertEquals('red_ptc_wfc', $item->property1);
            $this->assertEquals('blue_ptc_wfc', $item->property2);
            // Ensure that wake from cache is called in all cases.
            $this->assertGreaterThan($starttime, $item->propertytime);
        }

        // Test set many.
        $cache->set_many(array('key1' => 'data1', 'key2' => 'data2', 'key3' => null));
        $this->assertEquals('data1', $cache->get('key1'));
        $this->assertEquals('data2', $cache->get('key2'));
        $this->assertEquals(null, $cache->get('key3'));
        $this->assertTrue($cache->delete('key1'));
        $this->assertTrue($cache->delete('key2'));
        $this->assertTrue($cache->delete('key3'));

        $cache->set_many(array(
            'key1' => array(1, 2, 3),
            'key2' => array(3, 2, 1),
        ));
        $this->assertIsArray($cache->get('key1'));
        $this->assertIsArray($cache->get('key2'));
        $this->assertCount(3, $cache->get('key1'));
        $this->assertCount(3, $cache->get('key2'));
        $this->assertIsArray($cache->get_many(array('key1', 'key2')));
        $this->assertCount(2, $cache->get_many(array('key1', 'key2')));
        $this->assertEquals(2, $cache->delete_many(array('key1', 'key2')));

        // Test delete many.
        $this->assertTrue($cache->set('key1', 'data1'));
        $this->assertTrue($cache->set('key2', 'data2'));
        $this->assertTrue($cache->set('key3', null));

        $this->assertEquals('data1', $cache->get('key1'));
        $this->assertEquals('data2', $cache->get('key2'));
        $this->assertEquals(null, $cache->get('key3'));

        $this->assertEquals(3, $cache->delete_many(array('key1', 'key2', 'key3')));

        $this->assertFalse($cache->get('key1'));
        $this->assertFalse($cache->get('key2'));
        $this->assertFalse($cache->get('key3'));

        // Quick reference test.
        $obj = new \stdClass;
        $obj->key = 'value';
        $ref =& $obj;
        $this->assertTrue($cache->set('obj', $obj));

        $obj->key = 'eulav';
        $var = $cache->get('obj');
        $this->assertInstanceOf(\stdClass::class, $var);
        $this->assertEquals('value', $var->key);

        $ref->key = 'eulav';
        $var = $cache->get('obj');
        $this->assertInstanceOf(\stdClass::class, $var);
        $this->assertEquals('value', $var->key);

        $this->assertTrue($cache->delete('obj'));

        // Deep reference test.
        $obj1 = new \stdClass;
        $obj1->key = 'value';
        $obj2 = new \stdClass;
        $obj2->key = 'test';
        $obj3 = new \stdClass;
        $obj3->key = 'pork';
        $obj1->subobj =& $obj2;
        $obj2->subobj =& $obj3;
        $this->assertTrue($cache->set('obj', $obj1));

        $obj1->key = 'eulav';
        $obj2->key = 'tset';
        $obj3->key = 'krop';
        $var = $cache->get('obj');
        $this->assertInstanceOf(\stdClass::class, $var);
        $this->assertEquals('value', $var->key);
        $this->assertInstanceOf(\stdClass::class, $var->subobj);
        $this->assertEquals('test', $var->subobj->key);
        $this->assertInstanceOf(\stdClass::class, $var->subobj->subobj);
        $this->assertEquals('pork', $var->subobj->subobj->key);
        $this->assertTrue($cache->delete('obj'));

        // Death reference test... basically we don't want this to die.
        $obj = new \stdClass;
        $obj->key = 'value';
        $obj->self =& $obj;
        $this->assertTrue($cache->set('obj', $obj));
        $var = $cache->get('obj');
        $this->assertInstanceOf(\stdClass::class, $var);
        $this->assertEquals('value', $var->key);

        // Reference test after retrieve.
        $obj = new \stdClass;
        $obj->key = 'value';
        $this->assertTrue($cache->set('obj', $obj));

        $var1 = $cache->get('obj');
        $this->assertInstanceOf(\stdClass::class, $var1);
        $this->assertEquals('value', $var1->key);
        $var1->key = 'eulav';
        $this->assertEquals('eulav', $var1->key);

        $var2 = $cache->get('obj');
        $this->assertInstanceOf(\stdClass::class, $var2);
        $this->assertEquals('value', $var2->key);

        $this->assertTrue($cache->delete('obj'));

        // Death reference test on get_many... basically we don't want this to die.
        $obj = new \stdClass;
        $obj->key = 'value';
        $obj->self =& $obj;
        $this->assertEquals(1, $cache->set_many(array('obj' => $obj)));
        $var = $cache->get_many(array('obj'));
        $this->assertInstanceOf(\stdClass::class, $var['obj']);
        $this->assertEquals('value', $var['obj']->key);

        // Reference test after retrieve.
        $obj = new \stdClass;
        $obj->key = 'value';
        $this->assertEquals(1, $cache->set_many(array('obj' => $obj)));

        $var1 = $cache->get_many(array('obj'));
        $this->assertInstanceOf(\stdClass::class, $var1['obj']);
        $this->assertEquals('value', $var1['obj']->key);
        $var1['obj']->key = 'eulav';
        $this->assertEquals('eulav', $var1['obj']->key);

        $var2 = $cache->get_many(array('obj'));
        $this->assertInstanceOf(\stdClass::class, $var2['obj']);
        $this->assertEquals('value', $var2['obj']->key);

        $this->assertTrue($cache->delete('obj'));

        // Test strictness exceptions.
        try {
            $cache->get('exception', MUST_EXIST);
            $this->fail('Exception expected from cache::get using MUST_EXIST');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        try {
            $cache->get_many(array('exception1', 'exception2'), MUST_EXIST);
            $this->fail('Exception expected from cache::get_many using MUST_EXIST');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
        $cache->set('test', 'test');
        try {
            $cache->get_many(array('test', 'exception'), MUST_EXIST);
            $this->fail('Exception expected from cache::get_many using MUST_EXIST');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * Tests a definition using a data loader
     */
    public function test_definition_data_loader(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/datasourcetest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'datasourcetest',
            'datasource' => 'cache_phpunit_dummy_datasource',
            'datasourcefile' => 'cache/tests/fixtures/lib.php'
        ));

        $cache = cache::make('phpunit', 'datasourcetest');
        $this->assertInstanceOf(cache_application::class, $cache);

        // Purge it to be sure.
        $this->assertTrue($cache->purge());
        // It won't be there yet.
        $this->assertFalse($cache->has('Test'));
        // It should load it ;).
        $this->assertTrue($cache->has('Test', true));

        // Purge it to be sure.
        $this->assertTrue($cache->purge());
        $this->assertEquals('Test has no value really.', $cache->get('Test'));

        // Test multiple values.
        $this->assertTrue($cache->purge());
        $this->assertTrue($cache->set('b', 'B'));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertEquals('a has no value really.', $result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertEquals('c has no value really.', $result['c']);
    }

    /**
     * Tests a definition using a data loader with versioned keys.
     *
     * @covers ::get_versioned
     * @covers ::set_versioned
     */
    public function test_definition_data_loader_versioned(): void {
        // Create two definitions, one using a non-versionable data source and the other using
        // a versionable one.
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/datasourcetest1', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'datasourcetest1',
            'datasource' => 'cache_phpunit_dummy_datasource',
            'datasourcefile' => 'cache/tests/fixtures/lib.php'
        ));
        $instance->phpunit_add_definition('phpunit/datasourcetest2', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'datasourcetest2',
            'datasource' => 'cache_phpunit_dummy_datasource_versionable',
            'datasourcefile' => 'cache/tests/fixtures/lib.php'
        ));

        // The first data source works for normal 'get'.
        $cache1 = cache::make('phpunit', 'datasourcetest1');
        $this->assertEquals('Frog has no value really.', $cache1->get('Frog'));

        // But it doesn't work for get_versioned.
        try {
            $cache1->get_versioned('zombie', 1);
            $this->fail();
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Data source is not versionable', $e->getMessage());
        }

        // The second data source works for get_versioned. Set up the datasource first.
        $cache2 = cache::make('phpunit', 'datasourcetest2');

        $datasource = \cache_phpunit_dummy_datasource_versionable::get_last_instance();
        $datasource->has_value('frog', 3, 'Kermit');

        // Check data with no value.
        $this->assertFalse($cache2->get_versioned('zombie', 1));

        // Check data with value in datastore of required version.
        $result = $cache2->get_versioned('frog', 3, IGNORE_MISSING, $actualversion);
        $this->assertEquals('Kermit', $result);
        $this->assertEquals(3, $actualversion);

        // Check when the datastore doesn't have required version.
        $this->assertFalse($cache2->get_versioned('frog', 4));
    }

    /**
     * Tests a definition using an overridden loader
     */
    public function test_definition_overridden_loader(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/overridetest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'overridetest',
            'overrideclass' => 'cache_phpunit_dummy_overrideclass',
            'overrideclassfile' => 'cache/tests/fixtures/lib.php'
        ));
        $cache = cache::make('phpunit', 'overridetest');
        $this->assertInstanceOf(cache_phpunit_dummy_overrideclass::class, $cache);
        $this->assertInstanceOf(cache_application::class, $cache);
        // Purge it to be sure.
        $this->assertTrue($cache->purge());
        // It won't be there yet.
        $this->assertFalse($cache->has('Test'));
        // Add it.
        $this->assertTrue($cache->set('Test', 'Test has no value really.'));
        // Check its there.
        $this->assertEquals('Test has no value really.', $cache->get('Test'));
    }

    /**
     * Test the mappingsonly setting.
     */
    public function test_definition_mappings_only(): void {
        /** @var cache_config_testing $instance */
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/mappingsonly', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'mappingsonly',
            'mappingsonly' => true
        ), false);
        $instance->phpunit_add_definition('phpunit/nonmappingsonly', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'nonmappingsonly',
            'mappingsonly' => false
        ), false);

        $cacheonly = cache::make('phpunit', 'mappingsonly');
        $this->assertInstanceOf(cache_application::class, $cacheonly);
        $this->assertEquals('cachestore_dummy', $cacheonly->phpunit_get_store_class());

        $expected = $this->get_expected_application_cache_store();
        $cachenon = cache::make('phpunit', 'nonmappingsonly');
        $this->assertInstanceOf(cache_application::class, $cachenon);
        $this->assertEquals($expected, $cachenon->phpunit_get_store_class());
    }

    /**
     * Test a very basic definition.
     */
    public function test_definition(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/test', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'test',
        ));
        $cache = cache::make('phpunit', 'test');

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));
    }

    /**
     * Test a definition using the simple keys.
     */
    public function test_definition_simplekeys(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/simplekeytest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'simplekeytest',
            'simplekeys' => true
        ));
        $cache = cache::make('phpunit', 'simplekeytest');

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $cache->purge();

        $this->assertTrue($cache->set('1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('1'));
        $this->assertTrue($cache->set('2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('2'));
    }

    /**
     * Test a negative TTL on an application cache.
     */
    public function test_application_ttl_negative(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/ttltest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'ttltest',
            'ttl' => -86400 // Set to a day in the past to be extra sure.
        ));
        $cache = cache::make('phpunit', 'ttltest');
        $this->assertInstanceOf(cache_application::class, $cache);

        // Purge it to be sure.
        $this->assertTrue($cache->purge());
        // It won't be there yet.
        $this->assertFalse($cache->has('Test'));
        // Set it now.
        $this->assertTrue($cache->set('Test', 'Test'));
        // Check its not there.
        $this->assertFalse($cache->has('Test'));
        // Double check by trying to get it.
        $this->assertFalse($cache->get('Test'));

        // Test with multiple keys.
        $this->assertEquals(3, $cache->set_many(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertFalse($result['a']);
        $this->assertFalse($result['b']);
        $this->assertFalse($result['c']);

        // Test with multiple keys including missing ones.
        $result = $cache->get_many(array('a', 'c', 'e'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertArrayHasKey('e', $result);
        $this->assertFalse($result['a']);
        $this->assertFalse($result['c']);
        $this->assertFalse($result['e']);
    }

    /**
     * Test a positive TTL on an application cache.
     */
    public function test_application_ttl_positive(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/ttltest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'ttltest',
            'ttl' => 86400 // Set to a day in the future to be extra sure.
        ));
        $cache = cache::make('phpunit', 'ttltest');
        $this->assertInstanceOf(cache_application::class, $cache);

        // Purge it to be sure.
        $this->assertTrue($cache->purge());
        // It won't be there yet.
        $this->assertFalse($cache->has('Test'));
        // Set it now.
        $this->assertTrue($cache->set('Test', 'Test'));
        // Check its there.
        $this->assertTrue($cache->has('Test'));
        // Double check by trying to get it.
        $this->assertEquals('Test', $cache->get('Test'));

        // Test with multiple keys.
        $this->assertEquals(3, $cache->set_many(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertEquals('A', $result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertEquals('C', $result['c']);

        // Test with multiple keys including missing ones.
        $result = $cache->get_many(array('a', 'c', 'e'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertArrayHasKey('e', $result);
        $this->assertEquals('A', $result['a']);
        $this->assertEquals('C', $result['c']);
        $this->assertEquals(false, $result['e']);
    }

    /**
     * Test a negative TTL on an session cache.
     */
    public function test_session_ttl_positive(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/ttltest', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'ttltest',
            'ttl' => 86400 // Set to a day in the future to be extra sure.
        ));
        $cache = cache::make('phpunit', 'ttltest');
        $this->assertInstanceOf(cache_session::class, $cache);

        // Purge it to be sure.
        $this->assertTrue($cache->purge());
        // It won't be there yet.
        $this->assertFalse($cache->has('Test'));
        // Set it now.
        $this->assertTrue($cache->set('Test', 'Test'));
        // Check its there.
        $this->assertTrue($cache->has('Test'));
        // Double check by trying to get it.
        $this->assertEquals('Test', $cache->get('Test'));

        // Test with multiple keys.
        $this->assertEquals(3, $cache->set_many(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertEquals('A', $result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertEquals('C', $result['c']);

        // Test with multiple keys including missing ones.
        $result = $cache->get_many(array('a', 'c', 'e'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertArrayHasKey('e', $result);
        $this->assertEquals('A', $result['a']);
        $this->assertEquals('C', $result['c']);
        $this->assertEquals(false, $result['e']);
    }

    /**
     * Tests manual locking operations on an application cache
     */
    public function test_application_manual_locking(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/lockingtest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'lockingtest'
        ));
        // Configure the lock timeout so the test doesn't take too long to run.
        $instance->phpunit_edit_store_config('default_application', ['lockwait' => 2]);
        $cache1 = cache::make('phpunit', 'lockingtest');
        $cache2 = clone($cache1);

        $this->assertTrue($cache1->set('testkey', 'test data'));
        $this->assertTrue($cache2->set('testkey', 'test data'));

        $cache1->acquire_lock('testkey');
        try {
            $cache2->acquire_lock('testkey');
            $this->fail();
        } catch (\moodle_exception $e) {
            // Check the right exception message, and debug info mentions the store type.
            $this->assertMatchesRegularExpression('~Unable to acquire a lock.*cachestore_file.*~',
                    $e->getMessage());
        }

        $this->assertTrue($cache1->check_lock_state('testkey'));
        $this->assertFalse($cache2->check_lock_state('testkey'));

        $this->assertTrue($cache1->release_lock('testkey'));
        $this->assertFalse($cache2->release_lock('testkey'));

        $this->assertTrue($cache1->set('testkey', 'test data'));
        $this->assertTrue($cache2->set('testkey', 'test data'));
    }

    /**
     * Tests application cache event invalidation
     */
    public function test_application_event_invalidation(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventinvalidationtest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'eventinvalidationtest',
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $cache = cache::make('phpunit', 'eventinvalidationtest');

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Test invalidating a single entry.
        cache_helper::invalidate_by_event('crazyevent', array('testkey1'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $this->assertTrue($cache->set('testkey1', 'test data 1'));

        // Test invalidating both entries.
        cache_helper::invalidate_by_event('crazyevent', array('testkey1', 'testkey2'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Tests session cache event invalidation
     */
    public function test_session_event_invalidation(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/test_session_event_invalidation', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'test_session_event_invalidation',
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $cache = cache::make('phpunit', 'test_session_event_invalidation');
        $this->assertInstanceOf(cache_session::class, $cache);

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Test invalidating a single entry.
        cache_helper::invalidate_by_event('crazyevent', array('testkey1'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $this->assertTrue($cache->set('testkey1', 'test data 1'));

        // Test invalidating both entries.
        cache_helper::invalidate_by_event('crazyevent', array('testkey1', 'testkey2'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Tests application cache definition invalidation
     */
    public function test_application_definition_invalidation(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/definitioninvalidation', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'definitioninvalidation'
        ));
        $cache = cache::make('phpunit', 'definitioninvalidation');
        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        cache_helper::invalidate_by_definition('phpunit', 'definitioninvalidation', array(), 'testkey1');

        $this->assertFalse($cache->get('testkey1'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $this->assertTrue($cache->set('testkey1', 'test data 1'));

        cache_helper::invalidate_by_definition('phpunit', 'definitioninvalidation', array(), array('testkey1'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $this->assertTrue($cache->set('testkey1', 'test data 1'));

        cache_helper::invalidate_by_definition('phpunit', 'definitioninvalidation', array(), array('testkey1', 'testkey2'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Tests session cache definition invalidation
     */
    public function test_session_definition_invalidation(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/test_session_definition_invalidation', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'test_session_definition_invalidation'
        ));
        $cache = cache::make('phpunit', 'test_session_definition_invalidation');
        $this->assertInstanceOf(cache_session::class, $cache);
        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        cache_helper::invalidate_by_definition('phpunit', 'test_session_definition_invalidation', array(), 'testkey1');

        $this->assertFalse($cache->get('testkey1'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $this->assertTrue($cache->set('testkey1', 'test data 1'));

        cache_helper::invalidate_by_definition('phpunit', 'test_session_definition_invalidation', array(),
                array('testkey1'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        $this->assertTrue($cache->set('testkey1', 'test data 1'));

        cache_helper::invalidate_by_definition('phpunit', 'test_session_definition_invalidation', array(),
                array('testkey1', 'testkey2'));

        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Tests application cache event invalidation over a distributed setup.
     */
    public function test_distributed_application_event_invalidation(): void {
        global $CFG;
        // This is going to be an intense wee test.
        // We need to add data the to cache, invalidate it by event, manually force it back without MUC knowing to simulate a
        // disconnected/distributed setup (think load balanced server using local cache), instantiate the cache again and finally
        // check that it is not picked up.
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventinvalidationtest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'eventinvalidationtest',
            'simplekeys' => true,
            'simpledata' => true,
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $cache = cache::make('phpunit', 'eventinvalidationtest');
        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));

        cache_helper::invalidate_by_event('crazyevent', array('testkey1'));

        $this->assertFalse($cache->get('testkey1'));

        // OK data added, data invalidated, and invalidation time has been set.
        // Now we need to manually add back the data and adjust the invalidation time.
        $hash = md5(cache_store::MODE_APPLICATION.'/phpunit/eventinvalidationtest/'.$CFG->wwwroot.'phpunit');
        $timefile = $CFG->dataroot."/cache/cachestore_file/default_application/phpunit_eventinvalidationtest/las-cache/lastinvalidation-$hash.cache";
        // Make sure the file is correct.
        $this->assertTrue(file_exists($timefile));
        $timecont = serialize(cache::now(true) - 60); // Back 60sec in the past to force it to re-invalidate.
        make_writable_directory(dirname($timefile));
        file_put_contents($timefile, $timecont);
        $this->assertTrue(file_exists($timefile));

        $datafile = $CFG->dataroot."/cache/cachestore_file/default_application/phpunit_eventinvalidationtest/tes-cache/testkey1-$hash.cache";
        $datacont = serialize("test data 1");
        make_writable_directory(dirname($datafile));
        file_put_contents($datafile, $datacont);
        $this->assertTrue(file_exists($datafile));

        // Test 1: Rebuild without the event and test its there.
        cache_factory::reset();
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventinvalidationtest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'eventinvalidationtest',
            'simplekeys' => true,
            'simpledata' => true,
        ));
        $cache = cache::make('phpunit', 'eventinvalidationtest');
        $this->assertEquals('test data 1', $cache->get('testkey1'));

        // Test 2: Rebuild and test the invalidation of the event via the invalidation cache.
        cache_factory::reset();

        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventinvalidationtest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'eventinvalidationtest',
            'simplekeys' => true,
            'simpledata' => true,
            'invalidationevents' => array(
                'crazyevent'
            )
        ));

        $cache = cache::make('phpunit', 'eventinvalidationtest');
        $this->assertFalse($cache->get('testkey1'));

        // Test 3: Verify that an existing lastinvalidation cache file is updated when needed.

        // Make a new cache class.  This should should invalidate testkey2.
        $cache = cache::make('phpunit', 'eventinvalidationtest');

        // Invalidation token should have been reset.
        $this->assertEquals(cache::get_purge_token(), $cache->get('lastinvalidation'));

        // Set testkey2 data.
        $cache->set('testkey2', 'test data 2');

        // Backdate the event invalidation time by 30 seconds.
        $invalidationcache = cache::make('core', 'eventinvalidation');
        $invalidationcache->set('crazyevent', array('testkey2' => cache::now() - 30));

        // Lastinvalidation should already be cache::now().
        $this->assertEquals(cache::get_purge_token(), $cache->get('lastinvalidation'));

        // Set it to 15 seconds ago so that we know if it changes.
        $pasttime = cache::now(true) - 15;
        $cache->set('lastinvalidation', $pasttime);

        // Make a new cache class.  This should not invalidate anything.
        cache_factory::instance()->reset_cache_instances();
        $cache = cache::make('phpunit', 'eventinvalidationtest');

        // Lastinvalidation shouldn't change since it was already newer than invalidation event.
        $this->assertEquals($pasttime, $cache->get('lastinvalidation'));

        // Now set the event invalidation to newer than the lastinvalidation time.
        $invalidationcache->set('crazyevent', array('testkey2' => cache::now() - 5));
        // Make a new cache class.  This should should invalidate testkey2.
        cache_factory::instance()->reset_cache_instances();
        $cache = cache::make('phpunit', 'eventinvalidationtest');
        // Lastinvalidation timestamp should have updated to cache::now().
        $this->assertEquals(cache::get_purge_token(), $cache->get('lastinvalidation'));

        // Now simulate a purge_by_event 5 seconds ago.
        $invalidationcache = cache::make('core', 'eventinvalidation');
        $invalidationcache->set('crazyevent', array('purged' => cache::now(true) - 5));
        // Set our lastinvalidation timestamp to 15 seconds ago.
        $cache->set('lastinvalidation', cache::now(true) - 15);
        // Make a new cache class.  This should invalidate the cache.
        cache_factory::instance()->reset_cache_instances();
        $cache = cache::make('phpunit', 'eventinvalidationtest');
        // Lastinvalidation timestamp should have updated to cache::now().
        $this->assertEquals(cache::get_purge_token(), $cache->get('lastinvalidation'));

    }

    /**
     * Tests application cache event purge
     */
    public function test_application_event_purge(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventpurgetest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'eventpurgetest',
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $instance->phpunit_add_definition('phpunit/eventpurgetestaccelerated', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'eventpurgetestaccelerated',
            'staticacceleration' => true,
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $cache = cache::make('phpunit', 'eventpurgetest');

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Purge the event.
        cache_helper::purge_by_event('crazyevent');

        // Check things have been removed.
        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));

        // Now test the static acceleration array.
        $cache = cache::make('phpunit', 'eventpurgetestaccelerated');
        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Purge the event.
        cache_helper::purge_by_event('crazyevent');

        // Check things have been removed.
        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Tests session cache event purge
     */
    public function test_session_event_purge(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventpurgetest', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'eventpurgetest',
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $instance->phpunit_add_definition('phpunit/eventpurgetestaccelerated', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'eventpurgetestaccelerated',
            'staticacceleration' => true,
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $cache = cache::make('phpunit', 'eventpurgetest');

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Purge the event.
        cache_helper::purge_by_event('crazyevent');

        // Check things have been removed.
        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));

        // Now test the static acceleration array.
        $cache = cache::make('phpunit', 'eventpurgetestaccelerated');
        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Purge the event.
        cache_helper::purge_by_event('crazyevent');

        // Check things have been removed.
        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Tests application cache definition purge
     */
    public function test_application_definition_purge(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/definitionpurgetest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'definitionpurgetest',
            'invalidationevents' => array(
                'crazyevent'
            )
        ));
        $cache = cache::make('phpunit', 'definitionpurgetest');

        $this->assertTrue($cache->set('testkey1', 'test data 1'));
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        $this->assertTrue($cache->set('testkey2', 'test data 2'));
        $this->assertEquals('test data 2', $cache->get('testkey2'));

        // Purge the event.
        cache_helper::purge_by_definition('phpunit', 'definitionpurgetest');

        // Check things have been removed.
        $this->assertFalse($cache->get('testkey1'));
        $this->assertFalse($cache->get('testkey2'));
    }

    /**
     * Test the use of an alt path.
     * If we can generate a config instance we are done :)
     */
    public function test_alt_cache_path(): void {
        global $CFG;
        if ((defined('TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH') && TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH) || !empty($CFG->altcacheconfigpath)) {
            $this->markTestSkipped('Skipped testing alt cache path as it is already being used.');
        }
        $this->resetAfterTest();
        $CFG->altcacheconfigpath = $CFG->dataroot.'/cache/altcacheconfigpath';
        $instance = cache_config_testing::instance();
        $this->assertInstanceOf(cache_config::class, $instance);
    }

    /**
     * Test disabling the cache stores.
     */
    public function test_disable_stores(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/disabletest1', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'disabletest1'
        ));
        $instance->phpunit_add_definition('phpunit/disabletest2', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'disabletest2'
        ));
        $instance->phpunit_add_definition('phpunit/disabletest3', array(
            'mode' => cache_store::MODE_REQUEST,
            'component' => 'phpunit',
            'area' => 'disabletest3'
        ));

        $caches = array(
            'disabletest1' => cache::make('phpunit', 'disabletest1'),
            'disabletest2' => cache::make('phpunit', 'disabletest2'),
            'disabletest3' => cache::make('phpunit', 'disabletest3')
        );

        $this->assertInstanceOf(cache_phpunit_application::class, $caches['disabletest1']);
        $this->assertInstanceOf(cache_phpunit_session::class, $caches['disabletest2']);
        $this->assertInstanceOf(cache_phpunit_request::class, $caches['disabletest3']);

        $this->assertEquals('cachestore_file', $caches['disabletest1']->phpunit_get_store_class());
        $this->assertEquals('cachestore_session', $caches['disabletest2']->phpunit_get_store_class());
        $this->assertEquals('cachestore_static', $caches['disabletest3']->phpunit_get_store_class());

        foreach ($caches as $cache) {
            $this->assertFalse($cache->get('test'));
            $this->assertTrue($cache->set('test', 'test'));
            $this->assertEquals('test', $cache->get('test'));
        }

        cache_factory::disable_stores();

        $caches = array(
            'disabletest1' => cache::make('phpunit', 'disabletest1'),
            'disabletest2' => cache::make('phpunit', 'disabletest2'),
            'disabletest3' => cache::make('phpunit', 'disabletest3')
        );

        $this->assertInstanceOf(cache_phpunit_application::class, $caches['disabletest1']);
        $this->assertInstanceOf(cache_phpunit_session::class, $caches['disabletest2']);
        $this->assertInstanceOf(cache_phpunit_request::class, $caches['disabletest3']);

        $this->assertEquals('cachestore_dummy', $caches['disabletest1']->phpunit_get_store_class());
        $this->assertEquals('cachestore_dummy', $caches['disabletest2']->phpunit_get_store_class());
        $this->assertEquals('cachestore_dummy', $caches['disabletest3']->phpunit_get_store_class());

        foreach ($caches as $cache) {
            $this->assertFalse($cache->get('test'));
            $this->assertTrue($cache->set('test', 'test'));
            $this->assertEquals('test', $cache->get('test'));
        }
    }

    /**
     * Test disabling the cache.
     */
    public function test_disable(): void {
        global $CFG;

        if ((defined('TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH') && TEST_CACHE_USING_ALT_CACHE_CONFIG_PATH) || !empty($CFG->altcacheconfigpath)) {
            // We can't run this test as it requires us to delete the cache configuration script which we just
            // cant do with a custom path in play.
            $this->markTestSkipped('Skipped testing cache disable functionality as alt cache path is being used.');
        }

        $configfile = $CFG->dataroot.'/muc/config.php';

        // The config file will not exist yet as we've not done anything with the cache.
        // reset_all_data removes the file and without a call to create a configuration it doesn't exist
        // as yet.
        $this->assertFileDoesNotExist($configfile);

        // Disable the cache
        cache_phpunit_factory::phpunit_disable();

        // Check we get the expected disabled factory.
        $factory = cache_factory::instance();
        $this->assertInstanceOf(cache_factory_disabled::class, $factory);

        // Check we get the expected disabled config.
        $config = $factory->create_config_instance();
        $this->assertInstanceOf(cache_config_disabled::class, $config);

        // Check we get the expected disabled caches.
        $cache = cache::make('core', 'string');
        $this->assertInstanceOf(cache_disabled::class, $cache);

        // Test an application cache.
        $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'phpunit', 'disable');
        $this->assertInstanceOf(cache_disabled::class, $cache);

        $this->assertFalse($cache->get('test'));
        $this->assertFalse($cache->get_versioned('v', 1));
        $this->assertFalse($cache->set('test', 'test'));
        $this->assertFalse($cache->set_versioned('v', 1, 'data'));
        $this->assertFalse($cache->delete('test'));
        $this->assertTrue($cache->purge());
        // Checking a lock should always report that we have one.
        // Acquiring or releasing a lock should always report success.
        $this->assertTrue($cache->check_lock_state('test'));
        $this->assertTrue($cache->acquire_lock('test'));
        $this->assertTrue($cache->acquire_lock('test'));
        $this->assertTrue($cache->check_lock_state('test'));
        $this->assertTrue($cache->release_lock('test'));
        $this->assertTrue($cache->release_lock('test'));
        $this->assertTrue($cache->check_lock_state('test'));

        // Test a session cache.
        $cache = cache::make_from_params(cache_store::MODE_SESSION, 'phpunit', 'disable');
        $this->assertInstanceOf(cache_disabled::class, $cache);

        $this->assertFalse($cache->get('test'));
        $this->assertFalse($cache->get_versioned('v', 1));
        $this->assertFalse($cache->set('test', 'test'));
        $this->assertFalse($cache->set_versioned('v', 1, 'data'));
        $this->assertFalse($cache->delete('test'));
        $this->assertTrue($cache->purge());

        // Finally test a request cache.
        $cache = cache::make_from_params(cache_store::MODE_REQUEST, 'phpunit', 'disable');
        $this->assertInstanceOf(cache_disabled::class, $cache);

        $this->assertFalse($cache->get('test'));
        $this->assertFalse($cache->get_versioned('v', 1));
        $this->assertFalse($cache->set('test', 'test'));
        $this->assertFalse($cache->set_versioned('v', 1, 'data'));
        $this->assertFalse($cache->delete('test'));
        $this->assertTrue($cache->purge());

        cache_factory::reset();

        $factory = cache_factory::instance(true);
        $config = $factory->create_config_instance();
        $this->assertEquals('cache_config_testing', get_class($config));
    }

    /**
     * Test that multiple application loaders work ok.
     */
    public function test_multiple_application_loaders(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_file_store('phpunittest1');
        $instance->phpunit_add_file_store('phpunittest2');
        $instance->phpunit_add_definition('phpunit/multi_loader', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'multi_loader'
        ));
        $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'phpunittest1', 3);
        $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'phpunittest2', 2);

        $cache = cache::make('phpunit', 'multi_loader');
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->assertFalse($cache->get('test'));
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertEquals('test', $cache->get('test'));
        $this->assertTrue($cache->delete('test'));
        $this->assertFalse($cache->get('test'));
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertTrue($cache->purge());
        $this->assertFalse($cache->get('test'));

        // Test the many commands.
        $this->assertEquals(3, $cache->set_many(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertEquals('A', $result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertEquals('C', $result['c']);
        $this->assertEquals($result, $cache->get_many(array('a', 'b', 'c')));
        $this->assertEquals(2, $cache->delete_many(array('a', 'c')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertFalse($result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertFalse($result['c']);

        // Test non-recursive deletes.
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertSame('test', $cache->get('test'));
        $this->assertTrue($cache->delete('test', false));
        // We should still have it on a deeper loader.
        $this->assertSame('test', $cache->get('test'));
        // Test non-recusive with many functions.
        $this->assertSame(3, $cache->set_many(array(
            'one' => 'one',
            'two' => 'two',
            'three' => 'three'
        )));
        $this->assertSame('one', $cache->get('one'));
        $this->assertSame(array('two' => 'two', 'three' => 'three'), $cache->get_many(array('two', 'three')));
        $this->assertSame(3, $cache->delete_many(array('one', 'two', 'three'), false));
        $this->assertSame('one', $cache->get('one'));
        $this->assertSame(array('two' => 'two', 'three' => 'three'), $cache->get_many(array('two', 'three')));
    }

    /**
     * Data provider to try using a TTL or non-TTL cache.
     *
     * @return array
     */
    public function ttl_or_not(): array {
        return [[false], [true]];
    }

    /**
     * Data provider to try using a TTL or non-TTL cache, and static acceleration or not.
     *
     * @return array
     */
    public function ttl_and_static_acceleration_or_not(): array {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    /**
     * Data provider to try using a TTL or non-TTL cache, and simple data on or off.
     *
     * @return array
     */
    public function ttl_and_simple_data_or_not(): array {
        // Same values as for ttl and static acceleration (two booleans).
        return $this->ttl_and_static_acceleration_or_not();
    }

    /**
     * Shared code to set up a two or three-layer versioned cache for testing.
     *
     * @param bool $ttl If true, sets TTL in the definition
     * @param bool $threelayer If true, uses a 3-layer instead of 2-layer cache
     * @param bool $staticacceleration If true, enables static acceleration
     * @param bool $simpledata If true, enables simple data
     * @return \cache_application Cache
     */
    protected function create_versioned_cache(bool $ttl, bool $threelayer = false,
            bool $staticacceleration = false, bool $simpledata = false): \cache_application {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_file_store('a', false);
        $instance->phpunit_add_file_store('b', false);
        if ($threelayer) {
            $instance->phpunit_add_file_store('c', false);
        }
        $defarray = [
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'multi_loader'
        ];
        if ($ttl) {
            $defarray['ttl'] = '600';
        }
        if ($staticacceleration) {
            $defarray['staticacceleration'] = true;
            $defarray['staticaccelerationsize'] = 10;
        }
        if ($simpledata) {
            $defarray['simpledata'] = true;
        }
        $instance->phpunit_add_definition('phpunit/multi_loader', $defarray, false);
        $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'a', 1);
        $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'b', 2);
        if ($threelayer) {
            $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'c', 3);
        }

        $multicache = cache::make('phpunit', 'multi_loader');
        return $multicache;
    }

    /**
     * Tests basic use of versioned cache.
     *
     * @dataProvider ttl_and_simple_data_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @param bool $simpledata If true, turns on simple data flag
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_basic(bool $ttl, bool $simpledata): void {
        $multicache = $this->create_versioned_cache($ttl, false, false, $simpledata);

        $this->assertTrue($multicache->set_versioned('game', 1, 'Pooh-sticks'));

        $result = $multicache->get_versioned('game', 1, IGNORE_MISSING, $actualversion);
        $this->assertEquals('Pooh-sticks', $result);
        $this->assertEquals(1, $actualversion);
    }

    /**
     * Tests versioned cache with objects.
     *
     * @dataProvider ttl_and_static_acceleration_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @param bool $staticacceleration If true, enables static acceleration
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_objects(bool $ttl, bool $staticacceleration): void {
        $multicache = $this->create_versioned_cache($ttl, false, $staticacceleration);

        // Set an object value.
        $data = (object)['game' => 'Pooh-sticks'];
        $this->assertTrue($multicache->set_versioned('game', 1, $data));

        // Get it.
        $result = $multicache->get_versioned('game', 1);
        $this->assertEquals('Pooh-sticks', $result->game);

        // Mess about with the value in the returned object.
        $result->game = 'Tag';

        // Get it again and confirm the cached object has not been affected.
        $result = $multicache->get_versioned('game', 1);
        $this->assertEquals('Pooh-sticks', $result->game);
    }

    /**
     * Tests requesting a version that doesn't exist.
     *
     * @dataProvider ttl_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_not_exist(bool $ttl): void {
        $multicache = $this->create_versioned_cache($ttl);

        $multicache->set_versioned('game', 1, 'Pooh-sticks');

        // Exists but with wrong version.
        $this->assertFalse($multicache->get_versioned('game', 2));

        // Doesn't exist at all.
        $this->assertFalse($multicache->get_versioned('frog', 0));
    }

    /**
     * Tests attempts to use get after set_version or get_version after set.
     *
     * @dataProvider ttl_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_incompatible_versioning(bool $ttl): void {
        $multicache = $this->create_versioned_cache($ttl);

        // What if you use get on a get_version cache?
        $multicache->set_versioned('game', 1, 'Pooh-sticks');
        try {
            $multicache->get('game');
            $this->fail();
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unexpectedly found versioned cache entry', $e->getMessage());
        }

        // Or get_version on a get cache?
        $multicache->set('toy', 'Train set');
        try {
            $multicache->get_versioned('toy', 1);
            $this->fail();
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unexpectedly found non-versioned cache entry', $e->getMessage());
        }
    }

    /**
     * Versions are only stored once, so if you set a newer version you will always get it even
     * if you ask for the lower version number.
     *
     * @dataProvider ttl_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_single_copy(bool $ttl): void {
        $multicache = $this->create_versioned_cache($ttl);

        $multicache->set_versioned('game', 1, 'Pooh-sticks');
        $multicache->set_versioned('game', 2, 'Tag');
        $this->assertEquals('Tag', $multicache->get_versioned('game', 1, IGNORE_MISSING, $actualversion));

        // The reported version number matches the one returned, not requested.
        $this->assertEquals(2, $actualversion);
    }

    /**
     * If the first (local) store has an outdated copy but the second (shared) store has a newer
     * one, then it should automatically be retrieved.
     *
     * @dataProvider ttl_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_outdated_local(bool $ttl): void {
        $multicache = $this->create_versioned_cache($ttl);

        // Set initial value to version 2, 'Tag', in both stores.
        $multicache->set_versioned('game', 2, 'Tag');

        // Get the two separate cache stores for the multi-level cache.
        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'multi_loader');
        [0 => $storea, 1 => $storeb] = $factory->get_store_instances_in_use($definition);

        // Simulate what happens if the shared cache is updated with a new version but the
        // local one still has an old version.
        $hashgame = cache_helper::hash_key('game', $definition);
        $data = 'British Bulldog';
        if ($ttl) {
            $data = new \cache_ttl_wrapper($data, 600);
        }
        $storeb->set($hashgame, new \core_cache\version_wrapper($data, 3));

        // If we ask for the old one we'll get it straight off from local cache.
        $this->assertEquals('Tag', $multicache->get_versioned('game', 2));

        // But if we ask for the new one it will still get it via the shared cache.
        $this->assertEquals('British Bulldog', $multicache->get_versioned('game', 3));

        // Also, now it will have been updated in the local cache as well.
        $localvalue = $storea->get($hashgame);
        if ($ttl) {
            // In case the time has changed slightly since the first set, we can't do an exact
            // compare, so check it ignoring the time field.
            $this->assertEquals(3, $localvalue->version);
            $ttldata = $localvalue->data;
            $this->assertInstanceOf('cache_ttl_wrapper', $ttldata);
            $this->assertEquals('British Bulldog', $ttldata->data);
        } else {
            $this->assertEquals(new \core_cache\version_wrapper('British Bulldog', 3), $localvalue);
        }
    }

    /**
     * When we request a newer version, older ones are automatically deleted in every level of the
     * cache (to save I/O if there are multiple requests, as if there is another request it will
     * not have to retrieve the values to find out that they're old).
     *
     * @dataProvider ttl_or_not
     * @param bool $ttl If true, uses a TTL cache.
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_deleting_outdated(bool $ttl): void {
        $multicache = $this->create_versioned_cache($ttl);

        // Set initial value to version 2, 'Tag', in both stores.
        $multicache->set_versioned('game', 2, 'Tag');

        // Get the two separate cache stores for the multi-level cache.
        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'multi_loader');
        [0 => $storea, 1 => $storeb] = $factory->get_store_instances_in_use($definition);

        // If we request a newer version, then any older version should be deleted in each
        // cache level.
        $this->assertFalse($multicache->get_versioned('game', 4));
        $hashgame = cache_helper::hash_key('game', $definition);
        $this->assertFalse($storea->get($hashgame));
        $this->assertFalse($storeb->get($hashgame));
    }

    /**
     * Tests a versioned cache when using static cache.
     *
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_static(): void {
        $staticcache = $this->create_versioned_cache(false, false, true);

        // Set a value in the cache, version 1. This will store it in static acceleration.
        $staticcache->set_versioned('game', 1, 'Pooh-sticks');

        // Get the first cache store (we don't need the second one for this test).
        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'multi_loader');
        [0 => $storea] = $factory->get_store_instances_in_use($definition);

        // Hack a newer version into cache store without directly calling set (now the static
        // has v1, store has v2). This simulates another client updating the cache.
        $hashgame = cache_helper::hash_key('game', $definition);
        $storea->set($hashgame, new \core_cache\version_wrapper('Tag', 2));

        // Get the key from the cache, v1. This will use static acceleration.
        $this->assertEquals('Pooh-sticks', $staticcache->get_versioned('game', 1));

        // Now if we ask for a newer version, it should not use the static cached one.
        $this->assertEquals('Tag', $staticcache->get_versioned('game', 2));

        // This get should have updated static acceleration, so it will be used next time without
        // a store request.
        $storea->set($hashgame, new \core_cache\version_wrapper('British Bulldog', 3));
        $this->assertEquals('Tag', $staticcache->get_versioned('game', 2));

        // Requesting the higher version will get rid of static acceleration again.
        $this->assertEquals('British Bulldog', $staticcache->get_versioned('game', 3));

        // Finally ask for a version that doesn't exist anywhere, just to confirm it returns null.
        $this->assertFalse($staticcache->get_versioned('game', 4));
    }

    /**
     * Tests basic use of 3-layer versioned caches.
     *
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_3_layers_basic(): void {
        $multicache = $this->create_versioned_cache(false, true);

        // Basic use of set_versioned and get_versioned.
        $multicache->set_versioned('game', 1, 'Pooh-sticks');
        $this->assertEquals('Pooh-sticks', $multicache->get_versioned('game', 1));

        // What if you ask for a version that doesn't exist?
        $this->assertFalse($multicache->get_versioned('game', 2));

        // Setting a new version wipes out the old version; if you request it, you get the new one.
        $multicache->set_versioned('game', 2, 'Tag');
        $this->assertEquals('Tag', $multicache->get_versioned('game', 1));
    }

    /**
     * Tests use of 3-layer versioned caches where the 3 layers currently have different versions.
     *
     * @covers ::set_versioned
     * @covers ::get_versioned
     */
    public function test_versioned_cache_3_layers_different_data(): void {
        // Set version 2 using normal method.
        $multicache = $this->create_versioned_cache(false, true);
        $multicache->set_versioned('game', 2, 'Tag');

        // Get the three separate cache stores for the multi-level cache.
        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'multi_loader');
        [0 => $storea, 1 => $storeb, 2 => $storec] = $factory->get_store_instances_in_use($definition);

        // Set up two other versions so every level has a different version.
        $hashgame = cache_helper::hash_key('game', $definition);
        $storeb->set($hashgame, new \core_cache\version_wrapper('British Bulldog', 3));
        $storec->set($hashgame, new \core_cache\version_wrapper('Hopscotch', 4));

        // First request can be satisfied from A; second request requires B...
        $this->assertEquals('Tag', $multicache->get_versioned('game', 2));
        $this->assertEquals('British Bulldog', $multicache->get_versioned('game', 3));

        // And should update the data in A.
        $this->assertEquals(new \core_cache\version_wrapper('British Bulldog', 3), $storea->get($hashgame));
        $this->assertEquals('British Bulldog', $multicache->get_versioned('game', 1));

        // But newer data should still be in C.
        $this->assertEquals('Hopscotch', $multicache->get_versioned('game', 4));
        // Now it's stored in A and B too.
        $this->assertEquals(new \core_cache\version_wrapper('Hopscotch', 4), $storea->get($hashgame));
        $this->assertEquals(new \core_cache\version_wrapper('Hopscotch', 4), $storeb->get($hashgame));
    }

    /**
     * Test that multiple application loaders work ok.
     */
    public function test_multiple_session_loaders(): void {
        /* @var cache_config_testing $instance */
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_session_store('phpunittest1');
        $instance->phpunit_add_session_store('phpunittest2');
        $instance->phpunit_add_definition('phpunit/multi_loader', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'multi_loader'
        ));
        $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'phpunittest1', 3);
        $instance->phpunit_add_definition_mapping('phpunit/multi_loader', 'phpunittest2', 2);

        $cache = cache::make('phpunit', 'multi_loader');
        $this->assertInstanceOf(cache_session::class, $cache);
        $this->assertFalse($cache->get('test'));
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertEquals('test', $cache->get('test'));
        $this->assertTrue($cache->delete('test'));
        $this->assertFalse($cache->get('test'));
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertTrue($cache->purge());
        $this->assertFalse($cache->get('test'));

        // Test the many commands.
        $this->assertEquals(3, $cache->set_many(array('a' => 'A', 'b' => 'B', 'c' => 'C')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertEquals('A', $result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertEquals('C', $result['c']);
        $this->assertEquals($result, $cache->get_many(array('a', 'b', 'c')));
        $this->assertEquals(2, $cache->delete_many(array('a', 'c')));
        $result = $cache->get_many(array('a', 'b', 'c'));
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('a', $result);
        $this->assertArrayHasKey('b', $result);
        $this->assertArrayHasKey('c', $result);
        $this->assertFalse($result['a']);
        $this->assertEquals('B', $result['b']);
        $this->assertFalse($result['c']);

        // Test non-recursive deletes.
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertSame('test', $cache->get('test'));
        $this->assertTrue($cache->delete('test', false));
        // We should still have it on a deeper loader.
        $this->assertSame('test', $cache->get('test'));
        // Test non-recusive with many functions.
        $this->assertSame(3, $cache->set_many(array(
            'one' => 'one',
            'two' => 'two',
            'three' => 'three'
        )));
        $this->assertSame('one', $cache->get('one'));
        $this->assertSame(array('two' => 'two', 'three' => 'three'), $cache->get_many(array('two', 'three')));
        $this->assertSame(3, $cache->delete_many(array('one', 'two', 'three'), false));
        $this->assertSame('one', $cache->get('one'));
        $this->assertSame(array('two' => 'two', 'three' => 'three'), $cache->get_many(array('two', 'three')));
    }

    /**
     * Test switching users with session caches.
     */
    public function test_session_cache_switch_user(): void {
        $this->resetAfterTest(true);
        $cache = cache::make_from_params(cache_store::MODE_SESSION, 'phpunit', 'sessioncache');
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Log in as the first user.
        $this->setUser($user1);
        $sesskey1 = sesskey();

        // Set a basic value in the cache.
        $cache->set('var', 1);
        $this->assertTrue($cache->has('var'));
        $this->assertEquals(1, $cache->get('var'));

        // Change to the second user.
        $this->setUser($user2);
        $sesskey2 = sesskey();

        // Make sure the cache doesn't give us the data for the last user.
        $this->assertNotEquals($sesskey1, $sesskey2);
        $this->assertFalse($cache->has('var'));
        $this->assertEquals(false, $cache->get('var'));
    }

    /**
     * Test switching users with session caches.
     */
    public function test_session_cache_switch_user_application_mapping(): void {
        $this->resetAfterTest(true);
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_file_store('testfilestore');
        $instance->phpunit_add_definition('phpunit/testappsession', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'testappsession'
        ));
        $instance->phpunit_add_definition_mapping('phpunit/testappsession', 'testfilestore', 3);
        $cache = cache::make('phpunit', 'testappsession');
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Log in as the first user.
        $this->setUser($user1);
        $sesskey1 = sesskey();

        // Set a basic value in the cache.
        $cache->set('var', 1);
        $this->assertTrue($cache->has('var'));
        $this->assertEquals(1, $cache->get('var'));

        // Change to the second user.
        $this->setUser($user2);
        $sesskey2 = sesskey();

        // Make sure the cache doesn't give us the data for the last user.
        $this->assertNotEquals($sesskey1, $sesskey2);
        $this->assertFalse($cache->has('var'));
        $this->assertEquals(false, $cache->get('var'));
    }

    /**
     * Test two session caches being used at once to confirm collisions don't occur.
     */
    public function test_dual_session_caches(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/testsess1', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'testsess1'
        ));
        $instance->phpunit_add_definition('phpunit/testsess2', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'testsess2'
        ));
        $cache1 = cache::make('phpunit', 'testsess1');
        $cache2 = cache::make('phpunit', 'testsess2');

        $this->assertFalse($cache1->has('test'));
        $this->assertFalse($cache2->has('test'));

        $this->assertTrue($cache1->set('test', '1'));

        $this->assertTrue($cache1->has('test'));
        $this->assertFalse($cache2->has('test'));

        $this->assertTrue($cache2->set('test', '2'));

        $this->assertEquals(1, $cache1->get('test'));
        $this->assertEquals(2, $cache2->get('test'));

        $this->assertTrue($cache1->delete('test'));
    }

    /**
     * Test multiple session caches when switching user.
     */
    public function test_session_cache_switch_user_multiple(): void {
        $this->resetAfterTest(true);
        $cache1 = cache::make_from_params(cache_store::MODE_SESSION, 'phpunit', 'sessioncache1');
        $cache2 = cache::make_from_params(cache_store::MODE_SESSION, 'phpunit', 'sessioncache2');
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Log in as the first user.
        $this->setUser($user1);
        $sesskey1 = sesskey();

        // Set a basic value in the caches.
        $cache1->set('var', 1);
        $cache2->set('var', 2);
        $this->assertEquals(1, $cache1->get('var'));
        $this->assertEquals(2, $cache2->get('var'));

        // Change to the second user.
        $this->setUser($user2);
        $sesskey2 = sesskey();

        // Make sure the cache doesn't give us the data for the last user.
        // Also make sure that switching the user has lead to both caches being purged.
        $this->assertNotEquals($sesskey1, $sesskey2);
        $this->assertEquals(false, $cache1->get('var'));
        $this->assertEquals(false, $cache2->get('var'));
    }

    /**
     * The application locking feature should work with caches that support multiple identifiers
     * (static cache and MongoDB with a specific setting).
     *
     * @covers \cache_application
     */
    public function test_application_locking_multiple_identifier_cache(): void {
        // Get an arbitrary definition (modinfo).
        $instance = cache_config_testing::instance(true);
        $definitions = $instance->get_definitions();
        $definition = \cache_definition::load('phpunit', $definitions['core/coursemodinfo']);

        // Set up a static cache using that definition, wrapped in cache_application so we can do
        // locking.
        $store = new \cachestore_static('test');
        $store->initialise($definition);
        $cache = new cache_application($definition, $store);

        // Test the three locking functions.
        $cache->acquire_lock('frog');
        $this->assertTrue($cache->check_lock_state('frog'));
        $cache->release_lock('frog');
    }

    /**
     * Test requiring a lock before attempting to set a key.
     *
     * @covers ::set_implementation
     */
    public function test_application_locking_before_write(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/test_application_locking', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'test_application_locking',
            'staticacceleration' => true,
            'staticaccelerationsize' => 1,
            'requirelockingbeforewrite' => true
        ));
        $cache = cache::make('phpunit', 'test_application_locking');
        $this->assertInstanceOf(cache_application::class, $cache);

        $cache->acquire_lock('a');
        try {
            // Set with lock.
            $this->assertTrue($cache->set('a', 'A'));

            // Set without lock.
            try {
                $cache->set('b', 'B');
                $this->fail();
            } catch (\coding_exception $e) {
                $this->assertStringContainsString(
                        'Attempted to set cache key "b" without a lock. ' .
                        'Locking before writes is required for phpunit/test_application_locking',
                        $e->getMessage());
            }

            // Set many without full lock.
            try {
                $cache->set_many(['a' => 'AA', 'b' => 'BB']);
                $this->fail();
            } catch (\coding_exception $e) {
                $this->assertStringContainsString(
                        'Attempted to set cache key "b" without a lock.',
                        $e->getMessage());
            }

            // Check it didn't set key a either.
            $this->assertEquals('A', $cache->get('a'));

            // Set many with full lock.
            $cache->acquire_lock('b');
            try {
                $this->assertEquals(2, $cache->set_many(['a' => 'AA', 'b' => 'BB']));
                $this->assertEquals('AA', $cache->get('a'));
                $this->assertEquals('BB', $cache->get('b'));
            } finally {
                $cache->release_lock('b');
            }

            // Delete key with lock.
            $this->assertTrue($cache->delete('a'));
            $this->assertFalse($cache->get('a'));

            // Delete key without lock.
            try {
                $cache->delete('b');
                $this->fail();
            } catch (\coding_exception $e) {
                $this->assertStringContainsString(
                        'Attempted to delete cache key "b" without a lock.',
                        $e->getMessage());
            }

            // Delete many without full lock.
            $cache->set('a', 'AAA');
            try {
                $cache->delete_many(['a', 'b']);
                $this->fail();
            } catch (\coding_exception $e) {
                $this->assertStringContainsString(
                        'Attempted to delete cache key "b" without a lock.',
                        $e->getMessage());
            }
            // Nothing was deleted.
            $this->assertEquals('AAA', $cache->get('a'));

            // Delete many with full lock.
            $cache->acquire_lock('b');
            try {
                $this->assertEquals(2, $cache->delete_many(['a', 'b']));
            } finally {
                $cache->release_lock('b');
            }
            $this->assertFalse($cache->get('a'));
            $this->assertFalse($cache->get('b'));
        } finally {
            $cache->release_lock('a');
        }
    }

    /**
     * Test that locking before write works when writing across multiple layers.
     *
     * @covers \cache_loader
     * @return void
     */
    public function test_application_locking_multiple_layers(): void {

        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/test_application_locking', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'test_application_locking',
            'staticacceleration' => true,
            'staticaccelerationsize' => 1,
            'requirelockingbeforewrite' => true
        ), false);
        $instance->phpunit_add_file_store('phpunittest1');
        $instance->phpunit_add_file_store('phpunittest2');
        $instance->phpunit_add_definition_mapping('phpunit/test_application_locking', 'phpunittest1', 1);
        $instance->phpunit_add_definition_mapping('phpunit/test_application_locking', 'phpunittest2', 2);

        $cache = cache::make('phpunit', 'test_application_locking');
        $this->assertInstanceOf(cache_application::class, $cache);

        // Check that we can set a key across multiple layers.
        $cache->acquire_lock('a');
        $this->assertTrue($cache->set('a', 'A'));

        // Delete from the current layer.
        $cache->delete('a', false);
        $cache->release_lock('a');

        // Check that we can get the value from the deeper layer, which will also re-set it in the current one.
        $this->assertEquals('A', $cache->get('a'));

        // Try set/delete/get_many.
        $cache->acquire_lock('x');
        $cache->acquire_lock('y');
        $cache->acquire_lock('z');
        $this->assertEquals(3, $cache->set_many(['x' => 'X', 'y' => 'Y', 'z' => 'Z']));
        $cache->delete_many(['x', 'y', 'z'], false);
        $cache->release_lock('x');
        $cache->release_lock('y');
        $cache->release_lock('z');

        $this->assertEquals(['x' => 'X', 'y' => 'Y', 'z' => 'Z'], $cache->get_many(['x', 'y', 'z']));

        $cache->purge();

        // Try the tests again with a third layer.
        $instance->phpunit_add_file_store('phpunittest3');
        $instance->phpunit_add_definition_mapping('phpunit/test_application_locking', 'phpunittest3', 3);
        $cache = cache::make('phpunit', 'test_application_locking');
        $this->assertInstanceOf(cache_application::class, $cache);

        // Check that we can set a key across multiple layers.
        $cache->acquire_lock('a');
        $this->assertTrue($cache->set('a', 'A'));

        // Delete from the current layer.
        $cache->delete('a', false);
        $cache->release_lock('a');

        // Check that we can get the value from the deeper layer, which will also re-set it in the current one.
        $this->assertEquals('A', $cache->get('a'));

        // Try set/delete/get_many.
        $cache->acquire_lock('x');
        $cache->acquire_lock('y');
        $cache->acquire_lock('z');
        $this->assertEquals(3, $cache->set_many(['x' => 'X', 'y' => 'Y', 'z' => 'Z']));
        $cache->delete_many(['x', 'y', 'z'], false);
        $cache->release_lock('x');
        $cache->release_lock('y');
        $cache->release_lock('z');

        $this->assertEquals(['x' => 'X', 'y' => 'Y', 'z' => 'Z'], $cache->get_many(['x', 'y', 'z']));
    }

    /**
     * Tests that locking fails correctly when either layer of a 2-layer cache has a lock already.
     *
     * @covers \cache_loader
     */
    public function test_application_locking_multiple_layers_failures(): void {

        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/test_application_locking', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'test_application_locking',
            'staticacceleration' => true,
            'staticaccelerationsize' => 1,
            'requirelockingbeforewrite' => true
        ), false);
        $instance->phpunit_add_file_store('phpunittest1');
        $instance->phpunit_add_file_store('phpunittest2');
        $instance->phpunit_add_definition_mapping('phpunit/test_application_locking', 'phpunittest1', 1);
        $instance->phpunit_add_definition_mapping('phpunit/test_application_locking', 'phpunittest2', 2);

        $cache = cache::make('phpunit', 'test_application_locking');

        // We need to get the individual stores so as to set up the right behaviour here.
        $ref = new \ReflectionClass('\cache');
        $definitionprop = $ref->getProperty('definition');
        $storeprop = $ref->getProperty('store');
        $loaderprop = $ref->getProperty('loader');

        $definition = $definitionprop->getValue($cache);
        $localstore = $storeprop->getValue($cache);
        $sharedcache = $loaderprop->getValue($cache);
        $sharedstore = $storeprop->getValue($sharedcache);

        // Set the lock waiting time to 1 second so it doesn't take forever to run the test.
        $ref = new \ReflectionClass('\cachestore_file');
        $lockwaitprop = $ref->getProperty('lockwait');

        $lockwaitprop->setValue($localstore, 1);
        $lockwaitprop->setValue($sharedstore, 1);

        // Get key details and the cache identifier.
        $hashedkey = cache_helper::hash_key('apple', $definition);
        $localidentifier = $cache->get_identifier();
        $sharedidentifier = $sharedcache->get_identifier();

        // 1. Local cache is not locked but parent cache is locked.
        $sharedstore->acquire_lock($hashedkey, 'somebodyelse');
        try {
            try {
                $cache->acquire_lock('apple');
                $this->fail();
            } catch (\moodle_exception $e) {
            }

            // Neither store is locked by us, shared store still locked.
            $this->assertFalse((bool)$localstore->check_lock_state($hashedkey, $localidentifier));
            $this->assertFalse((bool)$sharedstore->check_lock_state($hashedkey, $sharedidentifier));
            $this->assertTrue((bool)$sharedstore->check_lock_state($hashedkey, 'somebodyelse'));
        } finally {
            $sharedstore->release_lock($hashedkey, 'somebodyelse');
        }

        // 2. Local cache is locked, parent cache is not locked.
        $localstore->acquire_lock($hashedkey, 'somebodyelse');
        try {
            try {
                $cache->acquire_lock('apple');
                $this->fail();
            } catch (\moodle_exception $e) {

            }

            // Neither store is locked by us, local store still locked.
            $this->assertFalse((bool)$localstore->check_lock_state($hashedkey, $localidentifier));
            $this->assertFalse((bool)$sharedstore->check_lock_state($hashedkey, $sharedidentifier));
            $this->assertTrue((bool)$localstore->check_lock_state($hashedkey, 'somebodyelse'));
        } finally {
            $localstore->release_lock($hashedkey, 'somebodyelse');
        }

        // 3. Just for completion, test what happens if we do lock it.
        $this->assertTrue($cache->acquire_lock('apple'));
        try {
            $this->assertTrue((bool)$localstore->check_lock_state($hashedkey, $localidentifier));
            $this->assertTrue((bool)$sharedstore->check_lock_state($hashedkey, $sharedidentifier));
        } finally {
            $cache->release_lock('apple');
        }
    }

    /**
     * Test the static cache_helper method purge_stores_used_by_definition.
     */
    public function test_purge_stores_used_by_definition(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/test_purge_stores_used_by_definition', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'test_purge_stores_used_by_definition'
        ));
        $cache = cache::make('phpunit', 'test_purge_stores_used_by_definition');
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->assertTrue($cache->set('test', 'test'));
        unset($cache);

        cache_helper::purge_stores_used_by_definition('phpunit', 'test_purge_stores_used_by_definition');

        $cache = cache::make('phpunit', 'test_purge_stores_used_by_definition');
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->assertFalse($cache->get('test'));
    }

    /**
     * Test purge routines.
     */
    public function test_purge_routines(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/purge1', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'purge1'
        ));
        $instance->phpunit_add_definition('phpunit/purge2', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'purge2',
            'requireidentifiers' => array(
                'id'
            )
        ));

        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'purge1');
        $this->assertFalse($definition->has_required_identifiers());
        $cache = $factory->create_cache($definition);
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertTrue($cache->has('test'));
        cache_helper::purge_by_definition('phpunit', 'purge1');
        $this->assertFalse($cache->has('test'));

        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'purge2');
        $this->assertTrue($definition->has_required_identifiers());
        $cache = $factory->create_cache($definition);
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->assertTrue($cache->set('test', 'test'));
        $this->assertTrue($cache->has('test'));
        cache_helper::purge_stores_used_by_definition('phpunit', 'purge2');
        $this->assertFalse($cache->has('test'));

        try {
            cache_helper::purge_by_definition('phpunit', 'purge2');
            $this->fail('Should not be able to purge a definition required identifiers without providing them.');
        } catch (\coding_exception $ex) {
            $this->assertStringContainsString('Identifier required for cache has not been provided', $ex->getMessage());
        }
    }

    /**
     * Tests that ad-hoc caches are correctly purged with a purge_all call.
     */
    public function test_purge_all_with_adhoc_caches(): void {
        $cache = cache::make_from_params(cache_store::MODE_REQUEST, 'core_cache', 'test');
        $cache->set('test', 123);
        cache_helper::purge_all();
        $this->assertFalse($cache->get('test'));
    }

    /**
     * Test that the default stores all support searching.
     */
    public function test_defaults_support_searching(): void {
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/search1', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'search1',
            'requiresearchable' => true
        ));
        $instance->phpunit_add_definition('phpunit/search2', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'search2',
            'requiresearchable' => true
        ));
        $instance->phpunit_add_definition('phpunit/search3', array(
            'mode' => cache_store::MODE_REQUEST,
            'component' => 'phpunit',
            'area' => 'search3',
            'requiresearchable' => true
        ));
        $factory = cache_factory::instance();

        // Test application cache is searchable.
        $definition = $factory->create_definition('phpunit', 'search1');
        $this->assertInstanceOf(cache_definition::class, $definition);
        $this->assertEquals(cache_store::IS_SEARCHABLE, $definition->get_requirements_bin() & cache_store::IS_SEARCHABLE);
        $cache = $factory->create_cache($definition);
        $this->assertInstanceOf(cache_application::class, $cache);
        $this->assertArrayHasKey('cache_is_searchable', $cache->phpunit_get_store_implements());

        // Test session cache is searchable.
        $definition = $factory->create_definition('phpunit', 'search2');
        $this->assertInstanceOf(cache_definition::class, $definition);
        $this->assertEquals(cache_store::IS_SEARCHABLE, $definition->get_requirements_bin() & cache_store::IS_SEARCHABLE);
        $cache = $factory->create_cache($definition);
        $this->assertInstanceOf(cache_session::class, $cache);
        $this->assertArrayHasKey('cache_is_searchable', $cache->phpunit_get_store_implements());

        // Test request cache is searchable.
        $definition = $factory->create_definition('phpunit', 'search3');
        $this->assertInstanceOf(cache_definition::class, $definition);
        $this->assertEquals(cache_store::IS_SEARCHABLE, $definition->get_requirements_bin() & cache_store::IS_SEARCHABLE);
        $cache = $factory->create_cache($definition);
        $this->assertInstanceOf(cache_request::class, $cache);
        $this->assertArrayHasKey('cache_is_searchable', $cache->phpunit_get_store_implements());
    }

    /**
     * Test static acceleration
     *
     * Note: All the assertGreaterThanOrEqual() in this test should be assertGreaterThan() be because of some microtime()
     * resolution problems under some OSs / PHP versions, we are accepting equal as valid outcome. For more info see MDL-57147.
     */
    public function test_static_acceleration(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/accelerated', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'accelerated',
            'staticacceleration' => true,
            'staticaccelerationsize' => 3,
        ));
        $instance->phpunit_add_definition('phpunit/accelerated2', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'accelerated2',
            'staticacceleration' => true,
            'staticaccelerationsize' => 3,
        ));
        $instance->phpunit_add_definition('phpunit/accelerated3', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'accelerated3',
            'staticacceleration' => true,
            'staticaccelerationsize' => 3,
        ));
        $instance->phpunit_add_definition('phpunit/accelerated4', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'accelerated4',
            'staticacceleration' => true,
            'staticaccelerationsize' => 4,
        ));
        $instance->phpunit_add_definition('phpunit/simpledataarea1', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'simpledataarea1',
            'staticacceleration' => true,
            'simpledata' => false
        ));
        $instance->phpunit_add_definition('phpunit/simpledataarea2', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'simpledataarea2',
            'staticacceleration' => true,
            'simpledata' => true
        ));

        $cache = cache::make('phpunit', 'accelerated');
        $this->assertInstanceOf(cache_phpunit_application::class, $cache);

        // Set and get three elements.
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('b', 'B'));
        $this->assertTrue($cache->set('c', 'C'));
        $this->assertEquals('A', $cache->get('a'));
        $this->assertEquals(array('b' => 'B', 'c' => 'C'), $cache->get_many(array('b', 'c')));

        // Make sure all items are in static acceleration array.
        $this->assertEquals('A', $cache->phpunit_static_acceleration_get('a'));
        $this->assertEquals('B', $cache->phpunit_static_acceleration_get('b'));
        $this->assertEquals('C', $cache->phpunit_static_acceleration_get('c'));

        // Add new value and make sure it is in cache and it is in array.
        $this->assertTrue($cache->set('d', 'D'));
        $this->assertEquals('D', $cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('D', $cache->get('d'));

        // Now the least recent accessed item (a) is no longer in acceleration array.
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertEquals('B', $cache->phpunit_static_acceleration_get('b'));
        $this->assertEquals('C', $cache->phpunit_static_acceleration_get('c'));

        // Adding and deleting element.
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->delete('a'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertFalse($cache->has('a'));

        // Make sure "purge" deletes from the array as well.
        $cache->purge();
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('b'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('c'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('d'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('e'));

        // Check that the array holds the last accessed items by get/set.
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('b', 'B'));
        $this->assertTrue($cache->set('c', 'C'));
        $this->assertTrue($cache->set('d', 'D'));
        $this->assertTrue($cache->set('e', 'E'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('b'));
        $this->assertEquals('C', $cache->phpunit_static_acceleration_get('c'));
        $this->assertEquals('D', $cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));

        // Store a cacheable_object, get many times and ensure each time wake_for_cache is used.
        // Both get and get_many are tested.  Two cache entries are used to ensure the times aren't
        // confused with multiple calls to get()/get_many().
        $startmicrotime = microtime(true);
        $cacheableobject = new cache_phpunit_dummy_object(1, 1, $startmicrotime);
        $cacheableobject2 = new cache_phpunit_dummy_object(2, 2, $startmicrotime);
        $this->assertTrue($cache->set('a', $cacheableobject));
        $this->assertTrue($cache->set('b', $cacheableobject2));
        $staticaccelerationreturntime = $cache->phpunit_static_acceleration_get('a')->propertytime;
        $staticaccelerationreturntimeb = $cache->phpunit_static_acceleration_get('b')->propertytime;
        $this->assertGreaterThanOrEqual($startmicrotime, $staticaccelerationreturntime, 'Restore time of static must be newer.');

        // Reset the static cache without resetting backing store.
        $cache->phpunit_static_acceleration_purge();

        // Get the value from the backend store, populating the static cache.
        $cachevalue = $cache->get('a');
        $this->assertInstanceOf(cache_phpunit_dummy_object::class, $cachevalue);
        $this->assertGreaterThanOrEqual($staticaccelerationreturntime, $cachevalue->propertytime);
        $backingstorereturntime = $cachevalue->propertytime;

        $results = $cache->get_many(array('b'));
        $this->assertInstanceOf(cache_phpunit_dummy_object::class, $results['b']);
        $this->assertGreaterThanOrEqual($staticaccelerationreturntimeb, $results['b']->propertytime);
        $backingstorereturntimeb = $results['b']->propertytime;

        // Obtain the value again and confirm that static cache is using wake_from_cache.
        // Upon failure, the times are not adjusted as wake_from_cache is skipped as the
        // value is stored serialized in the static acceleration cache.
        $cachevalue = $cache->phpunit_static_acceleration_get('a');
        $this->assertInstanceOf(cache_phpunit_dummy_object::class, $cachevalue);
        $this->assertGreaterThanOrEqual($backingstorereturntime, $cachevalue->propertytime);

        $results = $cache->get_many(array('b'));
        $this->assertInstanceOf(cache_phpunit_dummy_object::class, $results['b']);
        $this->assertGreaterThanOrEqual($backingstorereturntimeb, $results['b']->propertytime);

        /** @var cache_phpunit_application $cache */
        $cache = cache::make('phpunit', 'accelerated2');
        $this->assertInstanceOf(cache_phpunit_application::class, $cache);

        // Check that the array holds the last accessed items by get/set.
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('b', 'B'));
        $this->assertTrue($cache->set('c', 'C'));
        $this->assertTrue($cache->set('d', 'D'));
        $this->assertTrue($cache->set('e', 'E'));
        // Current keys in the array: c, d, e.
        $this->assertEquals('C', $cache->phpunit_static_acceleration_get('c'));
        $this->assertEquals('D', $cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('b'));

        $this->assertEquals('A', $cache->get('a'));
        // Current keys in the array: d, e, a.
        $this->assertEquals('D', $cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));
        $this->assertEquals('A', $cache->phpunit_static_acceleration_get('a'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('b'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('c'));

        // Current keys in the array: d, e, a.
        $this->assertEquals(array('c' => 'C'), $cache->get_many(array('c')));
        // Current keys in the array: e, a, c.
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));
        $this->assertEquals('A', $cache->phpunit_static_acceleration_get('a'));
        $this->assertEquals('C', $cache->phpunit_static_acceleration_get('c'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('b'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('d'));


        $cache = cache::make('phpunit', 'accelerated3');
        $this->assertInstanceOf(cache_phpunit_application::class, $cache);

        // Check that the array holds the last accessed items by get/set.
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('b', 'B'));
        $this->assertTrue($cache->set('c', 'C'));
        $this->assertTrue($cache->set('d', 'D'));
        $this->assertTrue($cache->set('e', 'E'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('b'));
        $this->assertEquals('C', $cache->phpunit_static_acceleration_get('c'));
        $this->assertEquals('D', $cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));

        $this->assertTrue($cache->set('b', 'B2'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertEquals('B2', $cache->phpunit_static_acceleration_get('b'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('c'));
        $this->assertEquals('D', $cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));

        $this->assertEquals(2, $cache->set_many(array('b' => 'B3', 'c' => 'C3')));
        $this->assertFalse($cache->phpunit_static_acceleration_get('a'));
        $this->assertEquals('B3', $cache->phpunit_static_acceleration_get('b'));
        $this->assertEquals('C3', $cache->phpunit_static_acceleration_get('c'));
        $this->assertFalse($cache->phpunit_static_acceleration_get('d'));
        $this->assertEquals('E', $cache->phpunit_static_acceleration_get('e'));

        $cache = cache::make('phpunit', 'accelerated4');
        $this->assertInstanceOf(cache_phpunit_application::class, $cache);
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertTrue($cache->set('a', 'A'));
        $this->assertEquals('A', $cache->phpunit_static_acceleration_get('a'));
        $this->assertEquals('A', $cache->get('a'));

        // Setting simpledata to false objects are cloned when retrieving data.
        $cache = cache::make('phpunit', 'simpledataarea1');
        $notreallysimple = new \stdClass();
        $notreallysimple->name = 'a';
        $cache->set('a', $notreallysimple);
        $returnedinstance1 = $cache->get('a');
        $returnedinstance2 = $cache->get('a');
        $returnedinstance1->name = 'b';
        $this->assertEquals('a', $returnedinstance2->name);

        // Setting simpledata to true we assume that data does not contain references.
        $cache = cache::make('phpunit', 'simpledataarea2');
        $notreallysimple = new \stdClass();
        $notreallysimple->name = 'a';
        $cache->set('a', $notreallysimple);
        $returnedinstance1 = $cache->get('a');
        $returnedinstance2 = $cache->get('a');
        $returnedinstance1->name = 'b';
        $this->assertEquals('b', $returnedinstance2->name);
    }

    public function test_identifiers_have_separate_caches(): void {
        $cachepg = cache::make('core', 'databasemeta', array('dbfamily' => 'pgsql'));
        $cachepg->set(1, 'here');
        $cachemy = cache::make('core', 'databasemeta', array('dbfamily' => 'mysql'));
        $cachemy->set(2, 'there');
        $this->assertEquals('here', $cachepg->get(1));
        $this->assertEquals('there', $cachemy->get(2));
        $this->assertFalse($cachemy->get(1));
    }

    public function test_performance_debug(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $CFG->perfdebug = 15;

        $instance = cache_config_testing::instance();
        $applicationid = 'phpunit/applicationperf';
        $instance->phpunit_add_definition($applicationid, array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'applicationperf'
        ));
        $sessionid = 'phpunit/sessionperf';
        $instance->phpunit_add_definition($sessionid, array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'sessionperf'
        ));
        $requestid = 'phpunit/requestperf';
        $instance->phpunit_add_definition($requestid, array(
            'mode' => cache_store::MODE_REQUEST,
            'component' => 'phpunit',
            'area' => 'requestperf'
        ));

        $application = cache::make('phpunit', 'applicationperf');
        $session = cache::make('phpunit', 'sessionperf');
        $request = cache::make('phpunit', 'requestperf');

        // Check that no stats are recorded for these definitions yet.
        $stats = cache_helper::get_stats();
        $this->assertArrayNotHasKey($applicationid, $stats);
        $this->assertArrayHasKey($sessionid, $stats);       // Session cache sets a key on construct.
        $this->assertArrayNotHasKey($requestid, $stats);

        // Check that stores register misses.
        $this->assertFalse($application->get('missMe'));
        $this->assertFalse($application->get('missMe'));
        $this->assertFalse($session->get('missMe'));
        $this->assertFalse($session->get('missMe'));
        $this->assertFalse($session->get('missMe'));
        $this->assertFalse($request->get('missMe'));
        $this->assertFalse($request->get('missMe'));
        $this->assertFalse($request->get('missMe'));
        $this->assertFalse($request->get('missMe'));

        $endstats = cache_helper::get_stats();
        $this->assertEquals(2, $endstats[$applicationid]['stores']['default_application']['misses']);
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['hits']);
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['sets']);
        $this->assertEquals(3, $endstats[$sessionid]['stores']['default_session']['misses']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['hits']);
        $this->assertEquals(1, $endstats[$sessionid]['stores']['default_session']['sets']);
        $this->assertEquals(4, $endstats[$requestid]['stores']['default_request']['misses']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['hits']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['sets']);

        $startstats = cache_helper::get_stats();

        // Check that stores register sets.
        $this->assertTrue($application->set('setMe1', 1));
        $this->assertTrue($application->set('setMe2', 2));
        $this->assertTrue($session->set('setMe1', 1));
        $this->assertTrue($session->set('setMe2', 2));
        $this->assertTrue($session->set('setMe3', 3));
        $this->assertTrue($request->set('setMe1', 1));
        $this->assertTrue($request->set('setMe2', 2));
        $this->assertTrue($request->set('setMe3', 3));
        $this->assertTrue($request->set('setMe4', 4));

        $endstats = cache_helper::get_stats();
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['misses'] -
                             $startstats[$applicationid]['stores']['default_application']['misses']);
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['hits'] -
                             $startstats[$applicationid]['stores']['default_application']['hits']);
        $this->assertEquals(2, $endstats[$applicationid]['stores']['default_application']['sets'] -
                             $startstats[$applicationid]['stores']['default_application']['sets']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['misses'] -
                             $startstats[$sessionid]['stores']['default_session']['misses']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['hits'] -
                             $startstats[$sessionid]['stores']['default_session']['hits']);
        $this->assertEquals(3, $endstats[$sessionid]['stores']['default_session']['sets'] -
                             $startstats[$sessionid]['stores']['default_session']['sets']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['misses'] -
                             $startstats[$requestid]['stores']['default_request']['misses']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['hits'] -
                             $startstats[$requestid]['stores']['default_request']['hits']);
        $this->assertEquals(4, $endstats[$requestid]['stores']['default_request']['sets'] -
                             $startstats[$requestid]['stores']['default_request']['sets']);

        $startstats = cache_helper::get_stats();

        // Check that stores register hits.
        $this->assertEquals($application->get('setMe1'), 1);
        $this->assertEquals($application->get('setMe2'), 2);
        $this->assertEquals($session->get('setMe1'), 1);
        $this->assertEquals($session->get('setMe2'), 2);
        $this->assertEquals($session->get('setMe3'), 3);
        $this->assertEquals($request->get('setMe1'), 1);
        $this->assertEquals($request->get('setMe2'), 2);
        $this->assertEquals($request->get('setMe3'), 3);
        $this->assertEquals($request->get('setMe4'), 4);

        $endstats = cache_helper::get_stats();
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['misses'] -
                             $startstats[$applicationid]['stores']['default_application']['misses']);
        $this->assertEquals(2, $endstats[$applicationid]['stores']['default_application']['hits'] -
                             $startstats[$applicationid]['stores']['default_application']['hits']);
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['sets'] -
                             $startstats[$applicationid]['stores']['default_application']['sets']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['misses'] -
                             $startstats[$sessionid]['stores']['default_session']['misses']);
        $this->assertEquals(3, $endstats[$sessionid]['stores']['default_session']['hits'] -
                             $startstats[$sessionid]['stores']['default_session']['hits']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['sets'] -
                             $startstats[$sessionid]['stores']['default_session']['sets']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['misses'] -
                             $startstats[$requestid]['stores']['default_request']['misses']);
        $this->assertEquals(4, $endstats[$requestid]['stores']['default_request']['hits'] -
                             $startstats[$requestid]['stores']['default_request']['hits']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['sets'] -
                             $startstats[$requestid]['stores']['default_request']['sets']);

        $startstats = cache_helper::get_stats();

        // Check that stores register through get_many.
        $application->get_many(array('setMe1', 'setMe2'));
        $session->get_many(array('setMe1', 'setMe2', 'setMe3'));
        $request->get_many(array('setMe1', 'setMe2', 'setMe3', 'setMe4'));

        $endstats = cache_helper::get_stats();
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['misses'] -
                             $startstats[$applicationid]['stores']['default_application']['misses']);
        $this->assertEquals(2, $endstats[$applicationid]['stores']['default_application']['hits'] -
                             $startstats[$applicationid]['stores']['default_application']['hits']);
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['sets'] -
                             $startstats[$applicationid]['stores']['default_application']['sets']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['misses'] -
                             $startstats[$sessionid]['stores']['default_session']['misses']);
        $this->assertEquals(3, $endstats[$sessionid]['stores']['default_session']['hits'] -
                             $startstats[$sessionid]['stores']['default_session']['hits']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['sets'] -
                             $startstats[$sessionid]['stores']['default_session']['sets']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['misses'] -
                             $startstats[$requestid]['stores']['default_request']['misses']);
        $this->assertEquals(4, $endstats[$requestid]['stores']['default_request']['hits'] -
                             $startstats[$requestid]['stores']['default_request']['hits']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['sets'] -
                             $startstats[$requestid]['stores']['default_request']['sets']);

        $startstats = cache_helper::get_stats();

        // Check that stores register through set_many.
        $this->assertEquals(2, $application->set_many(['setMe1' => 1, 'setMe2' => 2]));
        $this->assertEquals(3, $session->set_many(['setMe1' => 1, 'setMe2' => 2, 'setMe3' => 3]));
        $this->assertEquals(4, $request->set_many(['setMe1' => 1, 'setMe2' => 2, 'setMe3' => 3, 'setMe4' => 4]));

        $endstats = cache_helper::get_stats();

        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['misses'] -
            $startstats[$applicationid]['stores']['default_application']['misses']);
        $this->assertEquals(0, $endstats[$applicationid]['stores']['default_application']['hits'] -
            $startstats[$applicationid]['stores']['default_application']['hits']);
        $this->assertEquals(2, $endstats[$applicationid]['stores']['default_application']['sets'] -
            $startstats[$applicationid]['stores']['default_application']['sets']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['misses'] -
            $startstats[$sessionid]['stores']['default_session']['misses']);
        $this->assertEquals(0, $endstats[$sessionid]['stores']['default_session']['hits'] -
            $startstats[$sessionid]['stores']['default_session']['hits']);
        $this->assertEquals(3, $endstats[$sessionid]['stores']['default_session']['sets'] -
            $startstats[$sessionid]['stores']['default_session']['sets']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['misses'] -
            $startstats[$requestid]['stores']['default_request']['misses']);
        $this->assertEquals(0, $endstats[$requestid]['stores']['default_request']['hits'] -
            $startstats[$requestid]['stores']['default_request']['hits']);
        $this->assertEquals(4, $endstats[$requestid]['stores']['default_request']['sets'] -
            $startstats[$requestid]['stores']['default_request']['sets']);
    }

    /**
     * Data provider for static acceleration performance tests.
     *
     * @return array
     */
    public function static_acceleration_performance_provider(): array {
        // Note: These are the delta values, not the absolute values.
        // Also note that the set will actually store the valuein the static cache immediately.
        $validfirst = [
            'default_application' => [
                'hits' => 1,
                'misses' => 0,
            ],
            cache_store::STATIC_ACCEL => [
                'hits' => 0,
                'misses' => 1,
            ],
        ];

        $validsecond = [
            'default_application' => [
                'hits' => 0,
                'misses' => 0,
            ],
            cache_store::STATIC_ACCEL => [
                'hits' => 1,
                'misses' => 0,
            ],
        ];

        $invalidfirst = [
            'default_application' => [
                'hits' => 0,
                'misses' => 1,
            ],
            cache_store::STATIC_ACCEL => [
                'hits' => 0,
                'misses' => 1,
            ],
        ];
        $invalidsecond = [
            'default_application' => [
                'hits' => 0,
                'misses' => 1,
            ],
            cache_store::STATIC_ACCEL => [
                'hits' => 0,
                'misses' => 1,
            ],
        ];;

        return [
            'Truthy' => [
                true,
                $validfirst,
                $validsecond,
            ],
            'Null' => [
                null,
                $validfirst,
                $validsecond,
            ],
            'Empty Array' => [
                [],
                $validfirst,
                $validsecond,
            ],
            'Empty String' => [
                '',
                $validfirst,
                $validsecond,
            ],
            'False' => [
                false,
                $invalidfirst,
                $invalidsecond,
            ],
        ];
    }

    /**
     * Test performance of static acceleration caches with values which are frequently confused with missing values.
     *
     * @dataProvider static_acceleration_performance_provider
     * @param mixed $value The value to test
     * @param array $firstfetchstats The expected stats on the first fetch
     * @param array $secondfetchstats The expected stats on the subsequent fetch
     */
    public function test_static_acceleration_values_performance(
        $value,
        array $firstfetchstats,
        array $secondfetchstats,
    ): void {
        // Note: We need to modify perfdebug to test this.
        global $CFG;
        $this->resetAfterTest(true);
        $CFG->perfdebug = 15;

        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/accelerated', [
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'accelerated',
            'staticacceleration' => true,
            'staticaccelerationsize' => 1,
        ]);

        $cache = cache::make('phpunit', 'accelerated');
        $this->assertInstanceOf(cache_phpunit_application::class, $cache);

        $this->assertTrue($cache->set('value', $value));

        $checkstats = function(
            array $start,
            array $expectedstats,
        ): array {
            $applicationid = 'phpunit/accelerated';
            $endstats = cache_helper::get_stats();

            $start = $start[$applicationid]['stores'];
            $end = $endstats[$applicationid]['stores'];

            foreach ($expectedstats as $cachename => $expected) {
                foreach ($expected as $type => $value) {
                    $startvalue = array_key_exists($cachename, $start) ? $start[$cachename][$type] : 0;
                    $endvalue = array_key_exists($cachename, $end) ? $end[$cachename][$type] : 0;
                    $diff = $endvalue - $startvalue;
                    $this->assertEquals(
                        $value,
                        $diff,
                        "Expected $cachename $type to be $value, got $diff",
                    );
                }
            }

            return $endstats;
        };

        // Reset the cache factory so that we can get the stats from a fresh instance.
        $factory = cache_factory::instance();
        $factory->reset_cache_instances();
        $cache = cache::make('phpunit', 'accelerated');

        // Get the initial stats.
        $startstats = cache_helper::get_stats();

        // Fetching the value the first time should seed the static cache from the application cache.
        $this->assertEquals($value, $cache->get('value'));
        $startstats = $checkstats($startstats, $firstfetchstats);

        // Fetching the value should only hit the static cache.
        $this->assertEquals($value, $cache->get('value'));
        $checkstats($startstats, $secondfetchstats);
    }


    public function test_static_cache(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $CFG->perfdebug = 15;

        // Create cache store with static acceleration.
        $instance = cache_config_testing::instance();
        $applicationid = 'phpunit/applicationperf';
        $instance->phpunit_add_definition($applicationid, array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'applicationperf',
            'simplekeys' => true,
            'staticacceleration' => true,
            'staticaccelerationsize' => 3
        ));

        $application = cache::make('phpunit', 'applicationperf');

        // Check that stores register sets.
        $this->assertTrue($application->set('setMe1', 1));
        $this->assertTrue($application->set('setMe2', 0));
        $this->assertTrue($application->set('setMe3', array()));
        $this->assertTrue($application->get('setMe1') !== false);
        $this->assertTrue($application->get('setMe2') !== false);
        $this->assertTrue($application->get('setMe3') !== false);

        // Check that the static acceleration worked, even on empty arrays and the number 0.
        $endstats = cache_helper::get_stats();
        $this->assertEquals(0, $endstats[$applicationid]['stores']['** static accel. **']['misses']);
        $this->assertEquals(3, $endstats[$applicationid]['stores']['** static accel. **']['hits']);
    }

    public function test_performance_debug_off(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $CFG->perfdebug = 7;

        $instance = cache_config_testing::instance();
        $applicationid = 'phpunit/applicationperfoff';
        $instance->phpunit_add_definition($applicationid, array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'applicationperfoff'
        ));
        $sessionid = 'phpunit/sessionperfoff';
        $instance->phpunit_add_definition($sessionid, array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'sessionperfoff'
        ));
        $requestid = 'phpunit/requestperfoff';
        $instance->phpunit_add_definition($requestid, array(
            'mode' => cache_store::MODE_REQUEST,
            'component' => 'phpunit',
            'area' => 'requestperfoff'
        ));

        $application = cache::make('phpunit', 'applicationperfoff');
        $session = cache::make('phpunit', 'sessionperfoff');
        $request = cache::make('phpunit', 'requestperfoff');

        // Check that no stats are recorded for these definitions yet.
        $stats = cache_helper::get_stats();
        $this->assertArrayNotHasKey($applicationid, $stats);
        $this->assertArrayNotHasKey($sessionid, $stats);
        $this->assertArrayNotHasKey($requestid, $stats);

        // Trigger cache misses, cache sets and cache hits.
        $this->assertFalse($application->get('missMe'));
        $this->assertTrue($application->set('setMe', 1));
        $this->assertEquals(1, $application->get('setMe'));
        $this->assertFalse($session->get('missMe'));
        $this->assertTrue($session->set('setMe', 3));
        $this->assertEquals(3, $session->get('setMe'));
        $this->assertFalse($request->get('missMe'));
        $this->assertTrue($request->set('setMe', 4));
        $this->assertEquals(4, $request->get('setMe'));

        // Check that no stats are being recorded for these definitions.
        $endstats = cache_helper::get_stats();
        $this->assertArrayNotHasKey($applicationid, $endstats);
        $this->assertArrayNotHasKey($sessionid, $endstats);
        $this->assertArrayNotHasKey($requestid, $endstats);
    }

    /**
     * Tests session cache event purge and subsequent visit in the same request.
     *
     * This test simulates a cache being created, a value being set, then the value being purged.
     * A subsequent use of the same cache is started in the same request which fills the cache.
     * A new request is started a short time later.
     * The cache should be filled.
     */
    public function test_session_event_purge_same_second(): void {
        $instance = cache_config_testing::instance();
        $instance->phpunit_add_definition('phpunit/eventpurgetest', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'eventpurgetest',
            'invalidationevents' => array(
                'crazyevent',
            )
        ));

        // Create the cache, set a value, and immediately purge it by event.
        $cache = cache::make('phpunit', 'eventpurgetest');
        $cache->set('testkey1', 'test data 1');
        $this->assertEquals('test data 1', $cache->get('testkey1'));
        cache_helper::purge_by_event('crazyevent');
        $this->assertFalse($cache->get('testkey1'));

        // Set up the cache again in the same request and add a new value back in.
        $factory = cache_factory::instance();
        $factory->reset_cache_instances();
        $cache = cache::make('phpunit', 'eventpurgetest');
        $cache->set('testkey1', 'test data 2');
        $this->assertEquals('test data 2', $cache->get('testkey1'));

        // Trick the cache into thinking that this is a new request.
        cache_phpunit_cache::simulate_new_request();
        $factory = cache_factory::instance();
        $factory->reset_cache_instances();

        // Set up the cache again.
        // This is a subsequent request at a new time, so we instead the invalidation time will be checked.
        // The invalidation time should match the last purged time and the cache will not be re-purged.
        $cache = cache::make('phpunit', 'eventpurgetest');
        $this->assertEquals('test data 2', $cache->get('testkey1'));
    }

    /**
     * Test that values set in different sessions are stored with different key prefixes.
     */
    public function test_session_distinct_storage_key(): void {
        $this->resetAfterTest();

        // Prepare a dummy session cache configuration.
        $config = cache_config_testing::instance();
        $config->phpunit_add_definition('phpunit/test_session_distinct_storage_key', array(
            'mode' => cache_store::MODE_SESSION,
            'component' => 'phpunit',
            'area' => 'test_session_distinct_storage_key'
        ));

        // First anonymous user's session cache.
        cache_phpunit_session::phpunit_mockup_session_id('foo');
        $this->setUser(0);
        $cache1 = cache::make('phpunit', 'test_session_distinct_storage_key');

        // Reset cache instances to emulate a new request.
        cache_factory::instance()->reset_cache_instances();

        // Another anonymous user's session cache.
        cache_phpunit_session::phpunit_mockup_session_id('bar');
        $this->setUser(0);
        $cache2 = cache::make('phpunit', 'test_session_distinct_storage_key');

        cache_factory::instance()->reset_cache_instances();

        // Guest user's session cache.
        cache_phpunit_session::phpunit_mockup_session_id('baz');
        $this->setGuestUser();
        $cache3 = cache::make('phpunit', 'test_session_distinct_storage_key');

        cache_factory::instance()->reset_cache_instances();

        // Same guest user's session cache but in another browser window.
        cache_phpunit_session::phpunit_mockup_session_id('baz');
        $this->setGuestUser();
        $cache4 = cache::make('phpunit', 'test_session_distinct_storage_key');

        // Assert that different PHP session implies different key prefix for storing values.
        $this->assertNotEquals($cache1->phpunit_get_key_prefix(), $cache2->phpunit_get_key_prefix());

        // Assert that same PHP session implies same key prefix for storing values.
        $this->assertEquals($cache3->phpunit_get_key_prefix(), $cache4->phpunit_get_key_prefix());
    }
}
