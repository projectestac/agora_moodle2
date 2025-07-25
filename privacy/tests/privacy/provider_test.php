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

/**
 * Unit tests for all Privacy Providers.
 *
 * @package     core_privacy
 * @copyright   2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace core_privacy\privacy;

use core_privacy\manager;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\type;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\subsystem_link;

// phpcs:disable moodle.PHPUnit.TestCaseProvider.dataProviderSyntaxMethodNotFound

/**
 * Unit tests for all Privacy Providers.
 *
 * @copyright   2018 Andrew Nicols <andrew@nicols.co.uk>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core\tests\plugin_checks_testcase {
    /**
     * Returns a list of frankenstyle names of core components (plugins and subsystems).
     *
     * @return array the array of frankenstyle component names with the relevant class name.
     */
    public static function get_component_list(): array {
        $components = ['core' => [
            'component' => 'core',
            'classname' => manager::get_provider_classname_for_component('core'),
        ]];
        // Get all plugins.
        $plugintypes = \core_component::get_plugin_types();
        foreach ($plugintypes as $plugintype => $typedir) {
            $plugins = \core_component::get_plugin_list($plugintype);
            foreach ($plugins as $pluginname => $plugindir) {
                $frankenstyle = $plugintype . '_' . $pluginname;
                $components[$frankenstyle] = [
                    'component' => $frankenstyle,
                    'classname' => manager::get_provider_classname_for_component($frankenstyle),
                ];

            }
        }
        // Get all subsystems.
        foreach (\core_component::get_core_subsystems() as $name => $path) {
            if (isset($path)) {
                $frankenstyle = 'core_' . $name;
                $components[$frankenstyle] = [
                    'component' => $frankenstyle,
                    'classname' => manager::get_provider_classname_for_component($frankenstyle),
                ];
            }
        }
        return $components;
    }

    /**
     * Test that the specified null_provider works as expected.
     *
     * @group        plugin_checks
     * @dataProvider null_provider_provider
     * @param   string  $component The name of the component.
     * @param   string  $classname The name of the class for privacy
     */
    public function test_null_provider($component, $classname): void {
        $reason = $classname::get_reason();
        $this->assertIsString($reason);

        $this->assertIsString(get_string($reason, $component));
    }

    /**
     * Data provider for the null_provider tests.
     *
     * @return array
     */
    public static function null_provider_provider(): array {
        return array_filter(self::get_component_list(), function($component): bool {
            return static::component_implements(
                $component['classname'],
                \core_privacy\local\metadata\null_provider::class
            );
        });
    }

    /**
     * Test that the specified metadata_provider works as expected.
     *
     * @group        plugin_checks
     * @dataProvider metadata_provider_provider
     * @param   string  $component The name of the component.
     * @param   string  $classname The name of the class for privacy
     */
    public function test_metadata_provider($component, $classname): void {
        global $DB;

        $collection = new collection($component);
        $metadata = $classname::get_metadata($collection);
        $this->assertInstanceOf(collection::class, $metadata);
        $this->assertSame($collection, $metadata);
        $this->assertContainsOnlyInstancesOf(type::class, $metadata->get_collection());

        foreach ($metadata->get_collection() as $item) {
            // All items must have a valid string name.
            // Note: This is not a string identifier.
            $this->assertIsString($item->get_name());

            if ($item instanceof database_table) {
                // Check that the table is valid.
                $this->assertTrue($DB->get_manager()->table_exists($item->get_name()));
            }

            if ($item instanceof \core_privacy\local\metadata\types\plugintype_link) {
                // Check that plugin type is valid.
                $this->assertTrue(array_key_exists($item->get_name(), \core_component::get_plugin_types()));
            }

            if ($item instanceof subsystem_link) {
                // Check that core subsystem exists.
                list($plugintype, $pluginname) = \core_component::normalize_component($item->get_name());
                $this->assertEquals('core', $plugintype);
                $this->assertTrue(\core_component::is_core_subsystem($pluginname));
            }

            if ($summary = $item->get_summary()) {
                // Summary is optional, but when provided must be a valid string identifier.
                $this->assertIsString($summary);

                // Check that the string is also correctly defined.
                $this->assertIsString(get_string($summary, $component));
            }

            if ($fields = $item->get_privacy_fields()) {
                // Privacy fields are optional, but when provided must be a valid string identifier.
                foreach ($fields as $field => $identifier) {
                    $this->assertIsString($field);
                    $this->assertIsString($identifier);

                    // Check that the string is also correctly defined.
                    $this->assertIsString(get_string($identifier, $component));
                }
            }
        }
    }

    /**
     * Test that all providers implement some form of compliant provider.
     *
     * @group        plugin_checks
     * @dataProvider get_component_list
     * @param string $component frankenstyle component name, e.g. 'mod_assign'
     * @param string $classname the fully qualified provider classname
     */
    public function test_all_providers_compliant($component, $classname): void {
        $manager = new manager();
        $this->assertTrue($manager->component_is_compliant($component));
    }

    /**
     * Ensure that providers do not throw an error when processing a deleted user.
     *
     * @group           plugin_checks
     * @dataProvider    is_user_data_provider
     * @param   string  $component
     */
    public function test_userdata_provider_implements_userlist($component): void {
        $classname = manager::get_provider_classname_for_component($component);
        $this->assertTrue(is_subclass_of($classname, \core_privacy\local\request\core_userlist_provider::class));
    }

    /**
     * Data provider for the metadata\provider tests.
     *
     * @return array
     */
    public static function metadata_provider_provider(): array {
        return array_filter(self::get_component_list(), function($component): bool {
            return static::component_implements(
                $component['classname'],
                \core_privacy\local\metadata\provider::class
            );
        });
    }

    /**
     * List of providers which implement the core_user_data_provider.
     *
     * @return array
     */
    public static function is_user_data_provider(): array {
        return array_filter(self::get_component_list(), function($component): bool {
            return static::component_implements(
                $component['classname'],
                \core_privacy\local\request\core_user_data_provider::class
            );
        });
    }

    /**
     * Checks whether the component's provider class implements the specified interface, either directly or as a grandchild.
     *
     * @param   string  $providerclass The name of the class to test.
     * @param   string  $interface the name of the interface we want to check.
     * @return  bool    Whether the class implements the interface.
     */
    protected static function component_implements($providerclass, $interface) {
        if (class_exists($providerclass) && interface_exists($interface)) {
            return is_subclass_of($providerclass, $interface);
        }

        return false;
    }

    /**
     * Finds user fields in a table
     *
     * Returns fields that have foreign key to user table and fields that are named 'userid'.
     *
     * @param \xmldb_table $table
     * @return array
     */
    protected function get_userid_fields(\xmldb_table $table) {
        $userfields = [];

        // Find all fields that have a foreign key to 'id' field in 'user' table.
        $keys = $table->getKeys();
        foreach ($keys as $key) {
            $reffields = $key->getRefFields();
            $fields = $key->getFields();
            if ($key->getRefTable() === 'user' && count($reffields) == 1 && $reffields[0] == 'id' && count($fields) == 1) {
                $userfields[$fields[0]] = $fields[0];
            }
        }
        // Find fields with the name 'userid' even if they don't have a foreign key.
        $fields = $table->getFields();
        foreach ($fields as $field) {
            if ($field->getName() == 'userid') {
                $userfields['userid'] = 'userid';
            }
        }

        return $userfields;
    }

    /**
     * Test that all tables with user fields are covered by metadata providers
     *
     * @group        plugin_checks
     * @dataProvider get_component_list
     * @coversNothing
     * @param string $component frankenstyle component name, e.g. 'mod_assign'
     * @param string $classname the fully qualified provider classname
     */
    public function test_table_coverage(string $component, string $classname): void {
        global $DB;
        $dbman = $DB->get_manager(); // Load DDL classes.
        $tables = [];

        $filename = \core_component::get_component_directory($component) . '/db/install.xml';
        if (!file_exists($filename)) {
            $this->expectNotToPerformAssertions();
            return;
        }
        $xmldbfile = new \xmldb_file($filename);
        $this->assertTrue($xmldbfile->loadXMLStructure());;

        $structure = $xmldbfile->getStructure();
        $tablelist = $structure->getTables();

        foreach ($tablelist as $table) {
            if ($fields = $this->get_userid_fields($table)) {
                $tables[$table->getName()] = '  - ' . $table->getName() . ' (' . join(', ', $fields) . ')';
            }
        }

        $componentlist = self::metadata_provider_provider();
        foreach ($componentlist as $componentarray) {
            $component = $componentarray['component'];
            $classname = $componentarray['classname'];
            $collection = new collection($component);
            $metadata = $classname::get_metadata($collection);
            foreach ($metadata->get_collection() as $item) {
                if ($item instanceof database_table) {
                    unset($tables[$item->get_name()]);
                }
            }
        }

        if ($tables) {
            $this->fail("The following tables with user fields must be covered with metadata providers: \n".
                join("\n", $tables));
        }

    }
}
