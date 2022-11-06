<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2022 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace tests\units;

use DbTestCase;

/* Test for inc/profile.class.php */

class Profile extends DbTestCase
{
    /**
     * @see self::testHaveUserRight()
     *
     * @return array
     */
    protected function haveUserRightProvider()
    {

        return [
            [
                'user'     => [
                    'login'    => 'post-only',
                    'password' => 'postonly',
                ],
                'rightset' => [
                    ['name' => \Computer::$rightname, 'value' => CREATE, 'expected' => false],
                    ['name' => \Computer::$rightname, 'value' => DELETE, 'expected' => false],
                    ['name' => \Ticket::$rightname, 'value' => CREATE, 'expected' => true],
                    ['name' => \Ticket::$rightname, 'value' => DELETE, 'expected' => false],
                    ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDMYTICKET, 'expected' => true],
                    ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDALLTICKET, 'expected' => false],
                ],
            ],
            [
                'user'     => [
                    'login'    => 'glpi',
                    'password' => 'glpi',
                ],
                'rightset' => [
                    ['name' => \Computer::$rightname, 'value' => CREATE, 'expected' => true],
                    ['name' => \Computer::$rightname, 'value' => DELETE, 'expected' => true],
                    ['name' => \Ticket::$rightname, 'value' => CREATE, 'expected' => true],
                    ['name' => \Ticket::$rightname, 'value' => DELETE, 'expected' => true],
                    ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDMYTICKET, 'expected' => true],
                    ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDALLTICKET, 'expected' => true],
                ],
            ],
            [
                'user'     => [
                    'login'    => 'tech',
                    'password' => 'tech',
                ],
                'rightset' => [
                    ['name' => \Computer::$rightname, 'value' => CREATE, 'expected' => true],
                    ['name' => \Computer::$rightname, 'value' => DELETE, 'expected' => true],
                    ['name' => \Ticket::$rightname, 'value' => CREATE, 'expected' => true],
                    ['name' => \Ticket::$rightname, 'value' => DELETE, 'expected' => false],
                    ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDMYTICKET, 'expected' => true],
                    ['name' => \ITILFollowup::$rightname, 'value' => \ITILFollowup::ADDALLTICKET, 'expected' => true],
                ],
            ],
        ];
    }

    /**
     * Tests user rights checking.
     *
     * @param array   $user     Array containing 'login' and 'password' fields of tested user.
     * @param array   $rightset Array of arrays containing 'name', 'value' and 'expected' result of a right.
     *
     * @dataProvider haveUserRightProvider
     */
    public function testHaveUserRight(array $user, array $rightset)
    {

        $this->login($user['login'], $user['password']);

        foreach ($rightset as $rightdata) {
            $result = \Profile::haveUserRight(
                \Session::getLoginUserID(),
                $rightdata['name'],
                $rightdata['value'],
                0
            );
            $this->boolean($result)
             ->isEqualTo(
                 $rightdata['expected'],
                 sprintf('Unexpected result for value "%d" of "%s" right.', $rightdata['value'], $rightdata['name'])
             );
        }
    }

    /**
     * We try to login with tech profile and check if we can get a super-admin profile
     */
    public function testGetUnderActiveProfileRestrictCriteria()
    {
        global $DB;

        $this->login('tech', 'tech');

        $iterator = $DB->request([
            'FROM'   => \Profile::getTable(),
            'WHERE'  => \Profile::getUnderActiveProfileRestrictCriteria(),
            'ORDER'  => 'name'
        ]);

        foreach ($iterator as $profile_found) {
            $this->array($profile_found)->string['name']->isNotEqualTo('Super-Admin');
            $this->array($profile_found)->string['name']->isNotEqualTo('Admin');
        }
    }

    /**
     * Check we keep only necessary rights (at least for ticket)
     * when passing a profile from standard to self-service interface
     */
    public function testSwitchingInterface()
    {
        $ticket = new \Ticket();

       //create a temporay standard profile
        $profile = new \Profile();
        $profiles_id = $profile->add([
            'name'      => "test switch profile",
            'interface' => "standard",
        ]);

       // retrieve all tickets rights
        $all_rights = $ticket->getRights();
        $all_rights = array_keys($all_rights);
        $all_rights = array_fill_keys($all_rights, 1);

       // add all ticket rights to this profile
        $profile->update([
            'id'      => $profiles_id,
            '_ticket' => $all_rights
        ]);

       // switch to self-service interface
        $profile->update([
            'id'        => $profiles_id,
            'interface' => 'helpdesk'
        ]);

       // retrieve self-service tickets rights
        $ss_rights = $ticket->getRights("helpdesk");
        $ss_rights = array_keys($ss_rights);
        $ss_rights = array_fill_keys($ss_rights, 1);
        $exc_rights = array_diff_key($all_rights, $ss_rights);

       //reload profile
        $profile->getFromDB($profiles_id);

       // check removed rights is clearly removed
        foreach ($exc_rights as $right => $value) {
            $this->integer(($profile->fields['ticket'] & $right))->isEqualTo(0);
        }
       // check self-service rights is still here
        foreach ($ss_rights as $right => $value) {
            $this->integer(($profile->fields['ticket'] & $right))->isEqualTo($right);
        }
    }

    public function testClone()
    {
        global $DB;

        // Get default "Admin" profile
        $profile = new \Profile();
        $this->boolean($profile->getFromDB(3))->isTrue();

        // Clone it
        $cloned_profile = new \Profile();
        $clone_profiles_id = $profile->clone([
            'name' => __FUNCTION__,
        ]);
        $this->integer($clone_profiles_id)->isGreaterThan(0);
        $this->boolean($cloned_profile->getFromDB($clone_profiles_id))->isTrue();

        // Verify the original profile still references the source profile
        $this->integer($profile->fields['id'])->isEqualTo(3);

        // Some fields in the Profile itself to check that they are cloned
        $core_fields = ['interface', 'helpdesk_hardware', 'helpdesk_item_type'];
        foreach ($core_fields as $field) {
            if ($field === 'helpdesk_item_type') {
                $this->array(importArrayFromDB($cloned_profile->fields[$field]))->isEqualTo(importArrayFromDB($profile->fields[$field]));
            } else {
                $this->variable($cloned_profile->fields[$field])->isEqualTo($profile->fields[$field]);
            }
        }

        $rights_iterator = $DB->request([
            'SELECT' => ['profiles_id', 'name', 'rights'],
            'FROM'   => \ProfileRight::getTable(),
            'WHERE'  => ['profiles_id' => [3, $clone_profiles_id]],
        ]);
        // Check that all rights with profiles_id 3 exist with the clone ID as well
        $rights = [
            3 => [],
            $clone_profiles_id => [],
        ];
        foreach ($rights_iterator as $right) {
            $rights[$right['profiles_id']][$right['name']] = $right['rights'];
        }
        $this->integer(count($rights[$clone_profiles_id]))->isEqualTo(count($rights[3]));

        foreach ($rights[3] as $right => $value) {
            $this->integer($rights[$clone_profiles_id][$right])->isEqualTo($value);
        }
    }
}
