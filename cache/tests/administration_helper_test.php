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

use cache_config_testing;
use cache_config_writer;
use cache_factory;
use cache_helper;
use cache_store;

defined('MOODLE_INTERNAL') || die();

// Include the necessary evils.
global $CFG;
require_once($CFG->dirroot.'/cache/locallib.php');
require_once($CFG->dirroot.'/cache/tests/fixtures/lib.php');


/**
 * PHPunit tests for the cache API and in particular the core_cache\administration_helper
 *
 * @package    core_cache
 * @category   test
 * @copyright  2012 Sam Hemelryk
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class administration_helper_test extends \advanced_testcase {
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
     * Test the numerous summaries the helper can produce.
     */
    public function test_get_summaries(): void {
        // First the preparation.
        $config = cache_config_writer::instance();
        $this->assertTrue($config->add_store_instance('summariesstore', 'file'));
        $config->set_definition_mappings('core/eventinvalidation', array('summariesstore'));
        $this->assertTrue($config->set_mode_mappings(array(
            cache_store::MODE_APPLICATION => array('summariesstore'),
            cache_store::MODE_SESSION => array('default_session'),
            cache_store::MODE_REQUEST => array('default_request'),
        )));

        $storesummaries = administration_helper::get_store_instance_summaries();
        $this->assertIsArray($storesummaries);
        $this->assertArrayHasKey('summariesstore', $storesummaries);
        $summary = $storesummaries['summariesstore'];
        // Check the keys
        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('plugin', $summary);
        $this->assertArrayHasKey('default', $summary);
        $this->assertArrayHasKey('isready', $summary);
        $this->assertArrayHasKey('requirementsmet', $summary);
        $this->assertArrayHasKey('mappings', $summary);
        $this->assertArrayHasKey('modes', $summary);
        $this->assertArrayHasKey('supports', $summary);
        // Check the important/known values
        $this->assertEquals('summariesstore', $summary['name']);
        $this->assertEquals('file', $summary['plugin']);
        $this->assertEquals(0, $summary['default']);
        $this->assertEquals(1, $summary['isready']);
        $this->assertEquals(1, $summary['requirementsmet']);

        // Find the number of mappings to sessionstore.
        $mappingcount = count(array_filter($config->get_definitions(), function($element) {
            return $element['mode'] === cache_store::MODE_APPLICATION;
        }));
        $this->assertEquals($mappingcount, $summary['mappings']);

        $definitionsummaries = administration_helper::get_definition_summaries();
        $this->assertIsArray($definitionsummaries);
        $this->assertArrayHasKey('core/eventinvalidation', $definitionsummaries);
        $summary = $definitionsummaries['core/eventinvalidation'];
        // Check the keys
        $this->assertArrayHasKey('id', $summary);
        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('mode', $summary);
        $this->assertArrayHasKey('component', $summary);
        $this->assertArrayHasKey('area', $summary);
        $this->assertArrayHasKey('mappings', $summary);
        // Check the important/known values
        $this->assertEquals('core/eventinvalidation', $summary['id']);
        $this->assertInstanceOf('lang_string', $summary['name']);
        $this->assertEquals(cache_store::MODE_APPLICATION, $summary['mode']);
        $this->assertEquals('core', $summary['component']);
        $this->assertEquals('eventinvalidation', $summary['area']);
        $this->assertIsArray($summary['mappings']);
        $this->assertContains('summariesstore', $summary['mappings']);

        $pluginsummaries = administration_helper::get_store_plugin_summaries();
        $this->assertIsArray($pluginsummaries);
        $this->assertArrayHasKey('file', $pluginsummaries);
        $summary = $pluginsummaries['file'];
        // Check the keys
        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('requirementsmet', $summary);
        $this->assertArrayHasKey('instances', $summary);
        $this->assertArrayHasKey('modes', $summary);
        $this->assertArrayHasKey('supports', $summary);
        $this->assertArrayHasKey('canaddinstance', $summary);

        $locksummaries = administration_helper::get_lock_summaries();
        $this->assertIsArray($locksummaries);
        $this->assertTrue(count($locksummaries) > 0);

        $mappings = administration_helper::get_default_mode_stores();
        $this->assertIsArray($mappings);
        $this->assertCount(3, $mappings);
        $this->assertArrayHasKey(cache_store::MODE_APPLICATION, $mappings);
        $this->assertIsArray($mappings[cache_store::MODE_APPLICATION]);
        $this->assertContains('summariesstore', $mappings[cache_store::MODE_APPLICATION]);

        $potentials = administration_helper::get_definition_store_options('core', 'eventinvalidation');
        $this->assertIsArray($potentials); // Currently used, suitable, default
        $this->assertCount(3, $potentials);
        $this->assertArrayHasKey('summariesstore', $potentials[0]);
        $this->assertArrayHasKey('summariesstore', $potentials[1]);
        $this->assertArrayHasKey('default_application', $potentials[1]);
    }

    /**
     * Test instantiating an add store form.
     */
    public function test_get_add_store_form(): void {
        $form = cache_factory::get_administration_display_helper()->get_add_store_form('file');
        $this->assertInstanceOf('moodleform', $form);

        try {
            $form = cache_factory::get_administration_display_helper()->get_add_store_form('somethingstupid');
            $this->fail('You should not be able to create an add form for a store plugin that does not exist.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf('coding_exception', $e, 'Needs to be: ' .get_class($e)." ::: ".$e->getMessage());
        }
    }

    /**
     * Test instantiating a form to edit a store instance.
     */
    public function test_get_edit_store_form(): void {
        // Always instantiate a new core display helper here.
        $administrationhelper = new local\administration_display_helper;
        $config = cache_config_writer::instance();
        $this->assertTrue($config->add_store_instance('test_get_edit_store_form', 'file'));

        $form = $administrationhelper->get_edit_store_form('file', 'test_get_edit_store_form');
        $this->assertInstanceOf('moodleform', $form);

        try {
            $form = $administrationhelper->get_edit_store_form('somethingstupid', 'moron');
            $this->fail('You should not be able to create an edit form for a store plugin that does not exist.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf('coding_exception', $e);
        }

        try {
            $form = $administrationhelper->get_edit_store_form('file', 'blisters');
            $this->fail('You should not be able to create an edit form for a store plugin that does not exist.');
        } catch (\moodle_exception $e) {
            $this->assertInstanceOf('coding_exception', $e);
        }
    }

    /**
     * Test the hash_key functionality.
     */
    public function test_hash_key(): void {
        $this->resetAfterTest();
        set_debugging(DEBUG_ALL);

        // First with simplekeys
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/hashtest', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'hashtest',
            'simplekeys' => true
        ));
        $factory = cache_factory::instance();
        $definition = $factory->create_definition('phpunit', 'hashtest');

        $result = cache_helper::hash_key('test', $definition);
        $this->assertEquals('test-'.$definition->generate_single_key_prefix(), $result);

        try {
            cache_helper::hash_key('test/test', $definition);
            $this->fail('Invalid key was allowed, you should see this.');
        } catch (\coding_exception $e) {
            $this->assertEquals('test/test', $e->debuginfo);
        }

        // Second without simple keys
        $instance->phpunit_add_definition('phpunit/hashtest2', array(
            'mode' => cache_store::MODE_APPLICATION,
            'component' => 'phpunit',
            'area' => 'hashtest2',
            'simplekeys' => false
        ));
        $definition = $factory->create_definition('phpunit', 'hashtest2');

        $result = cache_helper::hash_key('test', $definition);
        $this->assertEquals(sha1($definition->generate_single_key_prefix().'-test'), $result);

        $result = cache_helper::hash_key('test/test', $definition);
        $this->assertEquals(sha1($definition->generate_single_key_prefix().'-test/test'), $result);
    }

    /**
     * Tests the get_usage function.
     */
    public function test_get_usage(): void {
        // Create a test cache definition and put items in it.
        $instance = cache_config_testing::instance(true);
        $instance->phpunit_add_definition('phpunit/test', [
                'mode' => cache_store::MODE_APPLICATION,
                'component' => 'phpunit',
                'area' => 'test',
                'simplekeys' => true
        ]);
        $cache = \cache::make('phpunit', 'test');
        for ($i = 0; $i < 100; $i++) {
            $cache->set('key' . $i, str_repeat('x', $i));
        }

        $factory = cache_factory::instance();
        $adminhelper = $factory->get_administration_display_helper();

        $usage = $adminhelper->get_usage(10)['phpunit/test'];
        $this->assertEquals('phpunit/test', $usage->cacheid);
        $this->assertCount(1, $usage->stores);
        $store = $usage->stores[0];
        $this->assertEquals('default_application', $store->name);
        $this->assertEquals('cachestore_file', $store->class);
        $this->assertEquals(true, $store->supported);
        $this->assertEquals(100, $store->items);

        // As file store checks all items, the values should be exact.
        $this->assertEqualsWithDelta(57.4, $store->mean, 0.1);
        $this->assertEqualsWithDelta(29.0, $store->sd, 0.1);
        $this->assertEquals(0, $store->margin);
    }
}
