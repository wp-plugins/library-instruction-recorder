<?php
/*
   Plugin Name: Library Instruction Recorder
   Plugin URI: http://bitbucket.org/gsulibwebmaster/library-instruction-recorder
   Description: A plugin for recording library instruction events and their associated data.
   Version: 1.1.0
   Author: Georgia State University Library
   Author URI: http://library.gsu.edu/
   License: GPLv3


   Library Instruction Recorder - A WordPress Plugin
   Copyright (C) 2013 Georgia State University Library

   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/


if(!class_exists('LIR')) {
   /*
      Class: LIR
         The LIR class which enables the Library Instruction Recorder functionality in WordPress.
   */
   class LIR {
      // Do not change these variables. The plugin name and slug can be changed on the settings page.
      const NAME = 'Library Instruction Recorder';
      const SLUG = 'LIR';
      const OPTIONS = 'lir_options';
      const OPTIONS_GROUP = 'lir_options_group';
      const VERSION = '1.1.0';
      const MIN_VERSION = '3.6';
      const TABLE_POSTS = '_posts';
      const TABLE_META = '_meta';
      const TABLE_FLAGS = '_flags';
      const SCHEDULE_TIME = '01:00:00';
      private static $defaultOptions = array(
         'version'         =>  self::VERSION,
         'debug'           =>  false,
         'name'            =>  self::NAME,
         'slug'            =>  self::SLUG,
         'intervalLength'  =>  15,
         'intervalAmount'  =>  16
      );
      private $options;
      private $tables;


      /*
         Constructor: __construct
            Adds register hooks, actions, and filters to WP.
      */
      public function __construct() {
         // Registration hooks.
         register_activation_hook(__FILE__, array(&$this, 'activationHook'));
         register_deactivation_hook(__FILE__, array(&$this, 'deactivationHook'));
         register_uninstall_hook(__FILE__, array(&$this, 'uninstallHook'));

         // Actions and filters.
         add_action('admin_menu', array(&$this, 'createMenu'));
         add_action('admin_init', array(&$this, 'adminInit'));
         add_action('admin_enqueue_scripts', array(&$this, 'addCssJS'));
         // A hook needs to be created to add this to the scheduler (weird, I know).
         add_action(self::SLUG.'_schedule', array(&$this, 'emailReminders'));
      }


      /*
         Function: init
            Initializes WordPress options, LIR table names, and the LIR scheduler. Why is this not
            in the construtor you ask? So that this stuff is not processed unless it is needed.

         Inputs:
            wpdb  -  Takes the global variable $wpdb by reference if it was already initialized.
      */
      private function init(&$wpdb = NULL) {
         // If these values are set then return.
         if(isset($this->options) && isset($this->tables)) { return; }
         // If not passed call global.
         if($wpdb == NULL) { global $wpdb; }

         // Load options, self::$defaultOptions if they do not exist.
         $this->options = get_option(self::OPTIONS, self::$defaultOptions);

         // Prep table names.
         $this->tables = array(
            'posts' => $wpdb->prefix.self::SLUG.self::TABLE_POSTS,
            'meta'  => $wpdb->prefix.self::SLUG.self::TABLE_META,
            'flags' => $wpdb->prefix.self::SLUG.self::TABLE_FLAGS
         );
      }


      /*
         Function: activationHook
            Checks to make sure WordPress is compatible, sets up tables, and sets up options.
            ***STATIC FUNCTION***

         See Also:
            <deactivationHook> and <uninstallHook>
      */
      public static function activationHook() {
         if(!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         // Make sure compatible WordPress version.
         global $wp_version;
         if(version_compare($wp_version, self::MIN_VERSION, '<')) {
            wp_die('This plugin requires WordPress version '.self::MIN_VERSION.' or higher.');
         }

         // If the option already exists it will not be overwritten.
         // Do not autoload the options, they are only used on admin pages.
         add_option(self::OPTIONS, self::$defaultOptions, '', 'no');

         // Add LIR tables to the database if they do not exist.
         global $wpdb;
         require_once(ABSPATH.'wp-admin/includes/upgrade.php'); // Required for dbDelta.

         // Post table.
         $query = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix.self::SLUG.self::TABLE_POSTS." (
                      id mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,
                      librarian_name varchar(255) NOT NULL,
                      librarian2_name varchar(255) DEFAULT NULL,
                      instructor_name varchar(255) NOT NULL,
                      instructor_email varchar(255) DEFAULT NULL,
                      instructor_phone varchar(255) DEFAULT NULL,
                      class_start datetime NOT NULL,
                      class_end datetime NOT NULL,
                      class_location varchar(255) NOT NULL,
                      class_type varchar(255) NOT NULL,
                      audience varchar(255) NOT NULL,
                      class_description mediumtext,
                      department_group varchar(255) NOT NULL,
                      course_number varchar(255) DEFAULT NULL,
                      attendance smallint(6) UNSIGNED DEFAULT NULL,
                      owner_id bigint(20) NOT NULL,
                      last_updated timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      last_updated_by bigint(20) NOT NULL,
                      PRIMARY KEY (id)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

         dbDelta($query);

         // Meta table.
         $query = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix.self::SLUG.self::TABLE_META." (
                      field varchar(255) NOT NULL,
                      value mediumtext NOT NULL,
                      PRIMARY KEY (field)
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

         dbDelta($query);

         // Flag table.
         $query = "CREATE TABLE IF NOT EXISTS ".$wpdb->prefix.self::SLUG.self::TABLE_FLAGS." (
                      posts_id mediumint(8) UNSIGNED NOT NULL,
                      name varchar(255) NOT NULL,
                      value smallint(1) NOT NULL,
                      PRIMARY KEY (posts_id, name),
                      FOREIGN KEY (posts_id)
                         REFERENCES ".$wpdb->prefix.self::SLUG.self::TABLE_POSTS." (id)
                         ON UPDATE CASCADE ON DELETE CASCADE
                   ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

         dbDelta($query);
      }


      /*
         Function: deactivationHook
            Removes LIR scheduler hook.
            ***STATIC FUNCTION***

         See Also:
            <activationHook> and <uninstallHook>
      */
      public static function deactivationHook() {
         if(!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         // Remove scheduled hook.
         wp_clear_scheduled_hook(self::SLUG.'_schedule');

         /* DO NOT UNCOMMENT THIS, IT WILL DELETE ALL OF YOUR DATA
         // Remove options saved in wp_options table.
         delete_option(self::OPTIONS);

         // Remove custom database tables (post & meta).
         global $wpdb;
         $wpdb->query("DROP TABLE IF EXISTS ".$wpdb->prefix.self::SLUG.self::TABLE_FLAGS.", ".$wpdb->prefix.self::SLUG.self::TABLE_META.", ".$wpdb->prefix.self::SLUG.self::TABLE_POSTS);
         //*/
      }


      /*
         Function: uninstallHook
            Used to cleanup items after uninstalling the plugin (databases, wp_options, &c).
            UNINSTALLING PERMANENTLY REMOVES ALL DATA ASSOCIATED WITH THIS PLUGIN.
            ***STATIC FUNCTION***

         See Also:
            <activationHook> and <deactivationHook>
      */
      public static function uninstallHook() {
         if(!current_user_can('manage_options') || !defined('WP_UNINSTALL_PLUGIN')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         // We'll make sure the scheduled hook has been removed.
         wp_clear_scheduled_hook(self::SLUG.'_schedule');

         // Remove WP options.
         delete_option(self::OPTIONS);

         // Remove custom database tables.
         global $wpdb;
         $wpdb->query("DROP TABLE IF EXISTS ".$wpdb->prefix.self::SLUG.self::TABLE_FLAGS.", ".$wpdb->prefix.self::SLUG.self::TABLE_META.", ".$wpdb->prefix.self::SLUG.self::TABLE_POSTS);
      }


      /*
         Function: adminInit
            Performs plugin updates, registers an option group so that the settings page
            functions and can be sanitized, makes sure the scheduler is set, and catches
            the post action for downloading reports.

         See Also:
            <settingsPage>, <sanitizeSettings>, and <generateReport>
      */
      public function adminInit() {
         $this->init();

         // Plugin upgrades are performed here.
         if($this->options['version'] != self::VERSION) {
            // Update options to current version.
            $this->options['version'] = self::VERSION;
            update_option(self::OPTIONS, $this->options);
         }

         register_setting(self::OPTIONS_GROUP, self::OPTIONS, array(&$this, 'sanitizeSettings'));

         // Setup/make sure scheduler is setup.
         if(!wp_next_scheduled(self::SLUG.'_schedule')) {
            wp_schedule_event(strtotime(self::SCHEDULE_TIME.' +1 day', time()), 'daily', self::SLUG.'_schedule');
         }

         // Generates and sends CSV file (before standard headers are sent).
         // It is worth noting that the admin_post_{action_name} hook only works (haven't tested it yet)
         // if being submitted to the /wp-admin/admin-post.php page. That doesn't work well for this
         // implementation since the report can be generated as a file or on the reports page.
         if(isset($_POST['action']) && ($_POST['action'] == self::SLUG.'_download_report')) {
            if($_POST['option'] == 'file') {
               $this->generateReport(true);
            }
         }
      }


      /*
         Function: addCssJS
            Adds custom CSS and JavaScript links to LIR pages (only).
      */
      public function addCssJS() {
         global $parent_file;

         // If admin page doesn't belong to LIR, do not add CSS and JS.
         if($parent_file != self::SLUG) { return; }

         // Register DataTables so that it can be a requirement for the admin.js script.
         wp_register_script(self::SLUG.'-dataTables', plugins_url('js/jquery.dataTables.min.js', __FILE__), array('jquery'), '1.10.1');
         wp_enqueue_script(self::SLUG.'-admin-JS', plugins_url('js/admin.js', __FILE__), array('jquery', self::SLUG.'-dataTables', 'jquery-ui-datepicker', 'jquery-ui-dialog'), self::VERSION);
         wp_enqueue_style(self::SLUG.'-admin-Css', plugins_url('css/admin.css', __FILE__), array(), self::VERSION);
         wp_enqueue_style(self::SLUG.'-jquery-ui-redmond', plugins_url('css/jquery-ui/redmond/jquery-ui.min.css', __FILE__), array(), '1.10.3');
         wp_enqueue_style(self::SLUG.'-dataTables-Css', plugins_url('css/dataTables/css/jquery.dataTables.min.css', __FILE__), array(), '1.10.1');
      }


      /*
         Function: createMenu
            Creates a menu and submenu on the dashboard for plugin usage and administration.

         See Also:
            <defaultPage>, <addClassPage>, <reportsPage>, <fieldsPage>, and <settingsPage>
      */
      public function createMenu() {
         $this->init();

         // Changes language of "add a class" on the submenu when editing a class.
         $addClassName = $_GET['edit'] ? 'Add/Edit a Class' : 'Add a Class';

         // Adds the main menu item.
         add_menu_page('', $this->options['slug'], 'read', self::SLUG, array(&$this, 'defaultPage'), '', '58.992');

         // Added so the first submenu item does not have the same title as the main menu item.
         add_submenu_page(self::SLUG, 'Upcoming Classes', 'Upcoming Classes', 'read', self::SLUG, array(&$this, 'defaultPage'));
         add_submenu_page(self::SLUG, $addClassName, $addClassName, 'edit_posts', self::SLUG.'-add-a-class', array(&$this, 'addClassPage'));
         add_submenu_page(self::SLUG, 'Reports', 'Reports', 'edit_posts', self::SLUG.'-reports', array(&$this, 'reportsPage'));
         add_submenu_page(self::SLUG, 'Fields', 'Fields', 'manage_options', self::SLUG.'-fields', array(&$this, 'fieldsPage'));
         add_submenu_page(self::SLUG, 'Settings', 'Settings', 'manage_options', self::SLUG.'-settings', array(&$this, 'settingsPage'));

         // This doesn't currently work as intended due to the way class updates are processed.
         $this->updateNotificationCount();
      }


      /*
         Function: defaultPage
            The default page is displayed when clicking on the LIR menu item. This page shows
            a list of upcoming classes while allowing users to see the details, edit entries,
            copy entries, and delete entries.

         Outputs:
            HTML for the default (upcoming classes) page.

         See Also:
            <addClassPage>
      */
      public function defaultPage() {
         if(!current_user_can('read')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         // If user is only a subscriber.
         $subscriber = current_user_can('edit_posts') ? false : true;
         global $wpdb, $current_user;
         $this->init($wpdb);
         get_currentuserinfo();
         $baseUrl = admin_url('admin.php?page='.self::SLUG);
         $delete = (!empty($_GET['delete'])) ? $_GET['delete'] : NULL;


         // Handle deletion if present.
         if($delete) {
            $query = $wpdb->prepare("SELECT * FROM ".$this->tables['posts']." WHERE id = %d", $delete);
            $class = $wpdb->get_row($query);

            // Check if the user has permissions to remove the class and verify the nonce.
            if((current_user_can('manage_options') || $current_user->id == $class->owner_id) && wp_verify_nonce($_GET['n'], self::SLUG.'-delete-'.$delete)) {
               $classRemoved = $wpdb->delete($this->tables['posts'], array('id' => $delete), '%d');
            }
         }


         // Queries to display the listings and also to give the appropriate counts for upcoming, incomplete, and previous classes.
         $upcoming        = $wpdb->get_results("SELECT * FROM ".$this->tables['posts']." WHERE NOW() <= class_end ORDER BY class_start, class_end");
         $upcomingCount   = $wpdb->num_rows;
         $incomplete      = $wpdb->get_results("SELECT * FROM ".$this->tables['posts']." WHERE NOW() > class_end AND attendance IS NULL ORDER BY class_start, class_end");
         $incompleteCount = $wpdb->num_rows;
         $previous        = $wpdb->get_results("SELECT * FROM ".$this->tables['posts']." WHERE NOW() > class_end ORDER BY class_start DESC, class_end");
         $previousCount   = $wpdb->num_rows;
         $myclasses       = $wpdb->get_results("SELECT * FROM ".$this->tables['posts']." WHERE owner_id = ".$current_user->id." ORDER BY class_start DESC, clasS_end");
         $myclassesCount  = $wpdb->num_rows;


         // Pick list to display and setup for link persistence.
         if($_GET['incomplete']) {
            $result = $incomplete;
         }
         else if($_GET['previous']) {
            $result = $previous;
         }
         else if($_GET['myclasses']) {
            $result = $myclasses;
         }
         else {
            $result = $upcoming;
         }

         ?>
         <div class="wrap">
            <h2>
               <?php
               echo $this->options['name'];
               if(!$subscriber) { echo ' <a href="'.$baseUrl.'-add-a-class" class="add-new-h2">Add a Class</a>'; }
               ?>
            </h2>

            <?php
            // For debugging.
            if($this->options['debug']) {
               echo '<div id="message" class="error">';
               echo '<p><strong>Time</strong><br />';
               echo time().' - '.date('c', time()).'</p>';
               echo '<p><strong>First Schedule?</strong><br />';
               echo strtotime(self::SCHEDULE_TIME.' +1 day', time()).' - '.date('c', strtotime(self::SCHEDULE_TIME.' +1 day', time())).'</p>';
               echo '<p><strong>Next Schedule</strong><br />';
               echo wp_next_scheduled(self::SLUG.'_schedule').' - '.date('c', wp_next_scheduled(self::SLUG.'_schedule')).'</p>';

               //global $menu;
               //echo '<pre>'.print_r($menu, true).'</pre>';

               echo '</div>';
            } // End debugging.


            // Success or error message for removing a class.
            if($delete && $classRemoved) {
               echo '<div id="message" class="updated">
                     <p><strong>The class has been removed!</strong></p>
                     </div>';
            }
            else if($delete && !$classRemoved) {
               echo '<div id="message" class="error">
                     <p><strong>There was a problem removing the class.</strong></p>
                     </div>';
            }
            ?>

            <ul class="subsubsub">
               <?php
               // Upcoming classes.
               echo '<li><a href="'.$baseUrl.'"';
               if(!$_GET['incomplete'] && !$_GET['previous'] && !$_GET['myclasses']) { echo ' class="current"'; }
               echo '>Upcoming <span class="count">('.$upcomingCount.')</span></a> |</li>';
               // Incomplete classes.
               echo '<li><a href="'.$baseUrl.'&incomplete=1"';
               if($_GET['incomplete'] == '1') { echo ' class="current"'; }
               echo '>Incomplete <span class="count">('.$incompleteCount.')</span></a> |</li>';
               // Previous classes.
               echo '<li><a href="'.$baseUrl.'&previous=1"';
               if($_GET['previous'] == '1') { echo ' class="current"'; }
               echo '>Previous <span class="count">('.$previousCount.')</span></a></li>';
               // My classes (if not a subscriber).
               if(!$subscriber) {
                  echo '<li>| <a href="'.$baseUrl.'&myclasses=1"';
                  if($_GET['myclasses'] == '1') { echo ' class="current"'; }
                  echo '>My Classes <span class="count">('.$myclassesCount.')</span></a></li>';
               }
               ?>
            </ul>

            <table id="classListingTable" class="widefat">
               <?php
               // Table header and footer.
               $tableHF  = '<tr><th class="check-column">&nbsp;</th><th>Department/Group</th><th>Course #</th><th>Date/Time</th>';
               $tableHF .= '<th>Primary Librarian</th><th>Instructor</th><th>Options</th><th class="hide">Hidden Goodies</th></tr>';
               echo '<thead>'.$tableHF.'</thead>';
               echo '<tfoot>'.$tableHF.'</tfoot>';

               // Table body.
               echo '<tbody>';

               // For each class.
               foreach($result as $class) {
                  echo '<tr class="'.self::SLUG.'-'.$class->id.'"';

                  if($class->class_description) {
                     echo ' title="'.$class->class_description.'"';
                  }
                  
                  echo '>'; // Closing the <tr...

                  echo '<th>&nbsp;</th>'; // Check-column.

                  echo '<td name="Department-Group">'.$class->department_group.'</td>';

                  if($class->course_number) {
                     echo '<td name="Course_Number">'.$class->course_number.'</td>';
                  }
                  else {
                     echo '<td>&nbsp;</td>';
                  }

                  // Display start date & time - end date & time.
                  if(substr($class->class_start, 0, 10) == substr($class->class_end, 0, 10)) {
                     echo '<td name="Date-Time"><span class="hide">'.$class->class_start.' - '.$class->class_end.'</span>'.date('n/j/Y (D) g:i A - ', strtotime($class->class_start));
                     echo date('g:i A', strtotime($class->class_end)).'</td>';
                  }
                  else { // If the end time is not on the same day as the start time.
                     echo '<td name="Date-Time">'.date('n/j/Y (D) g:i A -', strtotime($class->class_start)).'<br />';
                     echo date('n/j/Y (D) g:i A', strtotime($class->class_end)).'</td>';
                  }

                  echo '<td name="Primary_Librarian">'.$class->librarian_name.'</td>';

                  // Instructor name and email.
                  if($class->instructor_email) {
                     $mailto = esc_attr('mailto:'.$class->instructor_name.' <'.$class->instructor_email.'>');
                     echo '<td name="Instructor"><a href="'.$mailto.'" title="'.$class->instructor_email.'">'.$class->instructor_name.'</a></td>';
                  }
                  else {
                     echo '<td name="Instructor">'.$class->instructor_name.'</td>';
                  }

                  // Start Options section.
                  echo '<td><a class="stopLinkFire" href="#" onclick="showDetails(\''.self::SLUG.'-'.$class->id.'\')">Other Details</a>';

                  // Copy a class.
                  if(current_user_can('edit_posts')) {
                     echo ' | <a href="'.$baseUrl.'-add-a-class&copy='.$class->id.'">Copy</a>';
                  }

                  // Edit and delete links for classes.
                  if($class->owner_id == $current_user->id || current_user_can('manage_options')) {
                     $var = '';
                     if($_GET['incomplete'])     { $var = '&incomplete=1'; }
                     else if($_GET['previous'])  { $var = '&previous=1'; }
                     else if($_GET['myclasses']) { $var = '&myclasses=1'; }

                     echo ' | <a href="'.$baseUrl.'-add-a-class&edit='.$class->id.'">Edit</a>';
                     echo ' | <a href="#" class="stopLinkFire" onclick="removeClass(\''.$baseUrl.$var.'&delete='.$class->id.'&n='.wp_create_nonce(self::SLUG.'-delete-'.$class->id).'\')">Delete</a>';
                  }

                  // Hidden class details.
                  echo '<td class="hide otherDetails">';
                  if($class->librarian2_name) { echo '<span name="Secondary_Librarian">'.$class->librarian2_name.'</span>'; }
                  if($class->instructor_email) { echo '<span name="Instructor_Email">'.$class->instructor_email.'</span>'; }
                  if($class->instructor_phone) { echo '<span name="Instructor_Phone">'.$class->instructor_phone.'</span>'; }
                  echo '<span name="Class_Location">'.$class->class_location.'</span>';
                  echo '<span name="Class_Type">'.$class->class_type.'</span>';
                  echo '<span name="Audience">'.$class->audience.'</span>';
                  if($class->class_description) { echo '<span name="Class_Description">'.$class->class_description.'</span>'; }

                  // Flags.
                  $flags = $wpdb->get_results('SELECT name, value FROM '.$this->tables['flags']. ' WHERE posts_id = '.$class->id ,ARRAY_A);
                  foreach($flags as $f) {
                     echo '<span name="'.preg_replace(array('/[^0-9a-zA-Z\/]/', '/\//'), array('_', '-'), $f['name']).'">';
                     echo $f['value'] ? 'yes' : 'no';
                     echo '</span>';
                  }

                  echo '<span name="Attendance">'; echo $class->attendance ? $class->attendance : 'Not Yet Recorded'; echo '</span>';
                  echo '<span name="Last_Updated">'.date('n/j/Y g:i A', strtotime($class->last_updated)).'</span>';
                  echo '</td>';

                  echo '</td></tr>';
               }// End foreach loop for classes.
               ?>

               </tbody>
            </table>
         </div>
         <?php
      }


      /*
         Function: addClassPage
            The add a class page allows users to add a class to the instruction recorder. This
            page is also used for editing and copying existing entries.

         Outputs:
            HTML for the add a class page.

         See Also:
            <addUpdateClass>
      */
      public function addClassPage() {
         if(!current_user_can('edit_posts')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         global $user_identity, $wpdb, $current_user;
         $this->init($wpdb);
         get_currentuserinfo();
         $baseUrl = admin_url('admin.php?page='.self::SLUG.'-add-a-class');
         $classAdded = NULL;
         $error = array();


         // Prepare required meta fields (so we can check these).
         $departmentGroup = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'department_group_values'"));
         if(empty($departmentGroup)) { array_push($error, 'The department/group field is empty, please contact an administrator before continuing.'); }
         $classLocation = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'class_location_values'"));
         if(empty($classLocation)) { array_push($error, 'The class location field is empty, please contact an administrator before continuing.'); }
         $classType = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'class_type_values'"));
         if(empty($classType)) { array_push($error, 'The class type field is empty, please contact an administrator before continuing.'); }
         $audience = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'audience_values'"));
         if(empty($audience)) { array_push($error, 'The audience field is empty, please contact an administrator before continuing.'); }


         // Edit a class setup and permission checking (for edit).
         if($_GET['edit'] && !$_POST['edit']) {
            $class = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".$this->tables['posts']." WHERE id = %d", $_GET['edit']));

            // Save DB to POST so fields can be populated from same pool during editing and failed submissions.
            foreach($class as $x => $y) {
               $_POST[$x] = $y;
            }

            // Permission checking.
            if(!current_user_can('manage_options') && ($current_user->id != $class->owner_id)) {
               array_push($error, 'You do not have sufficient permissions to edit this class. <a href="'.$baseUrl.'">Add a new class?</a>');
               $_POST['submitted'] = NULL; // Ensures the class is never processed for submission.
            }
         }
         // If this is a copy class request.
         else if ($_GET['copy']) {
            $class = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".$this->tables['posts']." WHERE id = %d", $_GET['copy']));

            // Save DB to POST so fields can be populated from same pool during editing and failed submissions.
            foreach($class as $x => $y) {
               $_POST[$x] = $y;
            }
         }


         // Submission handling. **This should be done before headers are sent.**
         if(isset($_POST['submitted']) && ($debug['nonce verified'] = wp_verify_nonce($_POST[self::SLUG.'_nonce'], self::SLUG.'_add_class'))) {
            $classAdded = false; // Needed.

            // Check to make sure all required fields have been submitted.
            if(empty($_POST['librarian_name']))     { array_push($error, 'Missing Field: Primary Librarian'); }
            if(empty($_POST['instructor_name']))    { array_push($error, 'Missing Field: Instructor Name'); }
            if(empty($_POST['department_group']))   { array_push($error, 'Missing Field: Department/Group'); }
            if(empty($_POST['class_date']))         { array_push($error, 'Missing Field: Class Date'); }
            if(empty($_POST['class_time']))         { array_push($error, 'Missing Field: Class Time'); }
            if(empty($_POST['class_length']))       { array_push($error, 'Missing Field: Class Length'); }
            if(empty($_POST['class_location']))     { array_push($error, 'Missing Field: Class Location'); }
            if(empty($_POST['class_type']))         { array_push($error, 'Missing Field: Class Type'); }
            if(empty($_POST['audience']))           { array_push($error, 'Missing Field: Audience'); }

            // Go to function to insert data into database.
            if(empty($error)) { $classAdded = $this->addUpdateClass($_POST['edit']); }
            // This will make things easier from here on down, although should not happen.
            if($classAdded === 0) { $classAdded = true; }
            // If update fails with no other errors.
            if(!$classAdded && empty($error) && $_POST['edit']) { array_push($error, 'An error has occurred while trying to update the class. Please try again.'); }
            // If insert fails with no other errors.
            else if(!$classAdded && empty($error)) { array_push($error, 'An error has occurred while trying to submit the class. Please try again.'); }
         }
         ?>

         <div class="wrap">
            <h2><?= ($_GET['edit']) ? 'Edit' : 'Add'; ?> a Class</h2>

            <?php
            // Added for debugging (if set).
            if($this->options['debug']) {
               echo '<div id="message" class="error">';

               if(!empty($_POST)) {
                  echo '<p><strong>POST</strong></p>
                  <pre>'.print_r($_POST, true).'</pre>';

                  $array = preg_grep('/^flagName\d+/', array_keys($_POST));
                  echo '<p><strong>Flags</strong></p>
                  <pre>'.print_r($array, true).'</pre>';
               }


               if(!empty($debug)) {
                  echo '<p><strong>Other</strong></p>';

                  foreach($debug as $x => $y) {
                     echo '<p>'.$x.': '.$y.'</p>';
                  }
               }

               echo '<p>Last Query: '.$wpdb->last_query.'</p>';

               echo '<p>Class Added: ';
               if($classAdded) { echo $classAdded; }
               else if($classAdded === NULL) { echo 'NULL'; }
               else if($classAdded === false) { echo 'false'; }
               else { echo 'who knows'; }
               echo '</p>';

               echo '</div>';
            }
            // End debugging.

            // Message if an error occurred. 
            // Separating this from success messages since there are instances of a submission going through with an error present.
            if(!empty($error)) {
               echo '<div id="message" class="error">
                  <p><strong>';

                  foreach($error as $e) {
                     echo $e.'<br />';
                  }

                  echo '</strong></p>
               </div>';
            }
            // Message if class was added.
            if($classAdded && !$_POST['edit']) {
               echo '<div id="message" class="updated">
                  <p><strong>The class has been added!</strong> Need to <a href="'.$baseUrl.'&edit='.$classAdded.'">edit it</a>? <a href="'.$baseUrl.'&copy='.$classAdded.'">Copy it</a>? Would you like to <a href="'.$baseUrl.'">add a new class?</a></p>
               </div>';
            }
            // Message if class was updated.
            else if($classAdded && $_POST['edit']) {
               echo '<div id="message" class="updated">
                  <p><strong>The class has been updated!</strong> Need to <a href="'.$baseUrl.'&edit='.$_POST['edit'].'">edit it</a> again? <a href="'.$baseUrl.'&copy='.$_POST['edit'].'">Copy it</a>? Would you like to <a href="'.$baseUrl.'">add a new class?</a></p>
               </div>';
            }
            ?>

            <form action="" method="post">
               <table class="form-table">
                  <tr>
                     <th>*Primary Librarian</th>
                     <td><select name="librarian_name"><option value=""></option>

                     <?php
                     $user = $wpdb->get_results("SELECT display_name FROM ".$wpdb->users." ORDER BY display_name");

                     foreach($user as $u) {
                        if($u->display_name == "admin") { continue; }
                        echo '<option';

                        // If nothing has been submitted and it's a new submission select current user.
                        if(($classAdded === NULL) && !($_GET['edit'] || $_GET['copy']) && ($u->display_name == $user_identity)) {
                           echo ' selected="selected"';
                        }
                        // If this is an edit or copy that hasn't been submitted display the previous name.
                        else if(($classAdded === NULL) && ($_GET['edit'] || $_GET['copy']) && ($u->display_name == $_POST['librarian_name'])) {
                           echo ' selected="selected"';
                        }
                        // If there was a submission error display submitted name.
                        else if(($classAdded === false) && ($u->display_name == $_POST['librarian_name'])) {
                           echo ' selected="selected"';
                        }
                        // After a successful submission.
                        else if($classAdded && ($u->display_name == $user_identity)) {
                           echo ' selected="selected"';
                        }

                        echo ' value="'.$u->display_name.'">'.$u->display_name.'</option>';
                     }
                     ?>

                     </select></td>
                  </tr>
                  <tr>
                     <th>Secondary Librarian</th>
                     <td><select name="librarian2_name"><option value=""></option>

                     <?php
                     foreach($user as $u) {
                        if($u->display_name == "admin") { continue; }

                        if(!$classAdded && ($u->display_name == $_POST['librarian2_name'])) {
                           echo '<option value="'.$u->display_name.'" selected="selected">'.$u->display_name.'</option>';
                        }
                        else {
                           echo '<option value="'.$u->display_name.'">'.$u->display_name.'</option>';
                        }
                     }
                     ?>

                     </select></td>
                  </tr>
                  <tr>
                     <th>*Instructor Name</th>
                     <td><input type="text" name="instructor_name" value="<?php if(!$classAdded && !empty($_POST['instructor_name'])) echo $_POST['instructor_name']; ?>" /></td>
                  </tr>
                  <tr>
                     <th>Instructor Email</th>
                     <td><input type="email" name="instructor_email" value="<?php if(!$classAdded && !empty($_POST['instructor_email'])) echo $_POST['instructor_email']; ?>" /></td>
                  </tr>
                  <tr>
                     <th>Instructor Phone</th>
                     <td><input type="tel" name="instructor_phone" value="<?php if(!$classAdded && !empty($_POST['instructor_phone'])) echo $_POST['instructor_phone']; ?>" /></td>
                  </tr>
                  <tr>
                     <th>Class Description</th>
                     <td><textarea id="classDescription" name="class_description"><?php if(!$classAdded && !empty($_POST['class_description'])) echo $_POST['class_description']; ?></textarea></td>
                  </tr>
                  <tr>
                     <th>*Department/Group</th>
                     <td>
                        <select name="department_group">
                           <option value="">&nbsp;</option>

                           <?php
                           foreach($departmentGroup as $x) {
                              if(!$classAdded && (esc_attr($x) == $_POST['department_group'])) {
                                 echo '<option value="'.esc_attr($x).'" selected="selected">'.$x.'</option>';
                              }
                              else {
                                 echo '<option value="'.esc_attr($x).'">'.$x.'</option>';
                              }
                           }
                           ?>
                        </select>
                     </td>
                  </tr>
                  <tr>
                     <th>Course Number</th>
                     <td><input type="text" name="course_number" value="<?php if(!$classAdded && !empty($_POST['course_number'])) echo $_POST['course_number']; ?>" /></td>
                  </tr>
                  <tr>
                     <th>*Class Date (M/D/YYYY)</th>
                     <td>

                     <?php
                     echo '<input type="text" class="'.self::SLUG.'-date" name="class_date" value="';

                     if(!$classAdded && !empty($_POST['class_date'])) {
                        echo $_POST['class_date'];
                     }
                     else if(!$classAdded && !empty($_POST['class_start'])) {
                        echo date('n/j/Y', strtotime($_POST['class_start']));
                     }
                     else {
                        echo date('n/j/Y');
                     }

                     echo '" />';
                     ?>

                     </td>
                  </tr>
                  <tr>
                     <th>*Class Time (H:MM AM|PM)</th>

                     <?php
                     if(!$classAdded && !empty($_POST['class_time'])) {
                        $time = date('g:i A', strtotime($_POST['class_time']));
                     }
                     else if(!$classAdded && !empty($_POST['class_start'])) {
                        $time = date('g:i A', strtotime($_POST['class_start']));
                        $_POST['class_length'] = (strtotime($_POST['class_end']) - strtotime($_POST['class_start'])) / 60;
                     }
                     else {
                        $this->setTimeZone();

                        $minutes = date('i', strtotime("+15 minutes")) - date('i', strtotime("+15 minutes")) % 15;
                        $time = date('g:', strtotime("+15 minutes")).(($minutes) ? $minutes : '00').date(' A');
                     }
                     ?>

                     <td><input type="text" name="class_time" value="<?= $time; ?>" /> <label>*Length</label>
                        <select name="class_length">
                           <option value="0">&nbsp;</option>
                           <?php
                           for($i = 1; $i <= $this->options['intervalAmount']; $i++) {
                              $time = $this->options['intervalLength'] * $i;

                              echo '<option';
                              // Pre-select the option box if this is not a new class.
                              if(!$classAdded && !empty($_POST['class_length']) && ($_POST['class_length'] == $time)) { echo ' selected="selected"'; }
                              echo ' value="'.$time.'">';
                              if(floor($time / 60)) { echo floor($time / 60) == 1 ? '1 hour ' : floor($time / 60). ' hours '; }
                              if($time % 60) { echo  ($time % 60) == 1 ? '1 minute' : ($time % 60).' minutes'; }
                              echo '</option>';
                           }
                           ?>
                        </select>
                     </td>
                  </tr>
                  <tr>
                     <th>*Class Location</th>
                     <td><select name="class_location">
                         <option value="">&nbsp;</option>

                         <?php
                         foreach($classLocation as $x) {
                            echo '<option value="'.esc_attr($x).'"';

                            if(!$classAdded && $_POST['class_location'] == esc_attr($x)) {
                               echo ' selected="selected"';
                            }

                            echo '>'.$x.'</option>';
                         }
                         ?>

                         </select></td>
                  </tr>
                  <tr>
                     <th>*Class Type</th>
                     <td><select name="class_type">
                        <option value="">&nbsp;</option>

                        <?php
                        foreach($classType as $x) {
                           echo '<option value="'.esc_attr($x).'"';

                           if(!$classAdded && $_POST['class_type'] == esc_attr($x)) {
                              echo ' selected="selected"';
                           }

                           echo '>'.$x.'</option>';
                        }
                        ?>

                     </select></td>
                  </tr>
                  <tr>
                     <th>*Audience</th>
                     <td><select name="audience">
                        <option value="">&nbsp;</option>

                        <?php
                        foreach($audience as $x) {
                           echo '<option value="'.esc_attr($x).'"';

                           if(!$classAdded && $_POST['audience'] == esc_attr($x)) {
                              echo ' selected="selected"';
                           }

                           echo '>'.$x.'</option>';
                        }
                        ?>

                     </select></td>
                  </tr>

                  <?php
                  /*
                     Flags.
                  */
                  $i = 1;

                  // Non-submitted edits.
                  if(($classAdded === NULL) && (isset($_GET['edit']) || isset($_GET['copy']))) {
                     $tempID = isset($_GET['edit']) ? $_GET['edit'] : $_GET['copy'];
                     $flags = $wpdb->get_results($wpdb->prepare("SELECT name, value FROM ".$this->tables['flags']." WHERE posts_id = %d", $tempID, OBJECT));

                     foreach($flags as $f) {
                        echo '<tr><th>'.$f->name.'</th>';
                        echo '<td><input type="checkbox" name="flagValue'.$i.'" ';

                        if($f->value) {
                           echo 'checked="checked"';
                        }

                        echo ' />';
                        echo '<input type="hidden" name="flagName'.$i.'" value="'.esc_attr($f->name).'" /></td></tr>';
                        $i++;
                     }
                  }
                  // For new entries and after successful submissions.
                  else if(($classAdded === NULL) || $classAdded) {
                     $flags = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'flag_info'"));

                     foreach($flags as $name => $isEnabled) {
                        if($isEnabled) {
                           echo '<tr><th>'.$name.'</th>';
                           echo '<td><input type="checkbox" name="flagValue'.$i.'" />';
                           echo '<input type="hidden" name="flagName'.$i.'" value="'.esc_attr($name).'" /></td></tr>';
                           $i++;
                        }
                     }
                  }
                  // For failed submissions.
                  // This could potentially be manipulated by fake POST data.
                  else if($classAdded === false) {
                     echo '<!-- FLAGS (classAdded === false) -->';
                     $flagNames = preg_grep('/^flagName\d+/', array_keys($_POST));

                     foreach($flagNames as $name) {
                        $d = substr($name, -1, 1);

                        if(!empty($_POST[$name])) {
                           echo '<tr><th>'.$_POST[$name].'</th>';
                           echo '<td><input type="checkbox" name="flagValue'.$d.'"'; if($_POST['flagValue'.$d] == 'on') { echo ' checked="checked"'; } echo ' />';
                           echo '<input type="hidden" name="flagName'.$d.'" value="'.esc_attr($_POST[$name]).'" /></td></tr>';
                        }
                     }
                  }
                  ?>

                  <tr>
                     <th>Number of Students Attended</th>
                     <td><input type="number" name="attendance" value="<?php if(!$classAdded && !empty($_POST['attendance'])) echo $_POST['attendance']; ?>" /></td>
                  </tr>
               </table>

               <?php wp_nonce_field(self::SLUG.'_add_class', self::SLUG.'_nonce'); ?>
               <?php if($_GET['edit']) echo '<input type="hidden" name="edit" value="'.$_GET['edit'].'" />'; ?>

               <p class="submit">
                  <input type="submit" name="submitted" class="button-primary" value="Submit" />&nbsp;&nbsp;
                  <input type="button" class="button-primary" value="Cancel" onclick="location.href = '<?= admin_url('admin.php?page='.self::SLUG); ?>'" />
               </p>
            </form>
         </div>
         <?php
      }


      /*
         Function: addUpdateClass
            Adds or updates a class listing in the database. Handles the sanitation of all of the 
            inputs.

         Inputs:
            id  -  The id of the entry being updated (NULL if new entry).

         Returns:
            id     -  ID of effected row on success.
            false  -  False if entry was not added/updated.
      */
      private function addUpdateClass($id = NULL) {
         global $wpdb, $current_user;
         $this->init($wpdb);
         get_currentuserinfo();


         $dataTypes = array();
         $myQuery = $id ? 'UPDATE ' : 'INSERT INTO ';
         $myQuery .= $this->tables['posts'].' SET';

         // Not NULL columns.
         $myQuery .= ' librarian_name = %s,';
         array_push($dataTypes, $_POST['librarian_name']);
         $myQuery .= ' instructor_name = %s,';
         array_push($dataTypes, $_POST['instructor_name']);
         $myQuery .= ' class_location = %s,';
         array_push($dataTypes, $_POST['class_location']);
         $myQuery .= ' class_type = %s,';
         array_push($dataTypes, $_POST['class_type']);
         $myQuery .= ' audience = %s,';
         array_push($dataTypes, $_POST['audience']);
         $myQuery .= ' department_group = %s,';
         array_push($dataTypes, $_POST['department_group']);
         $myQuery .= ' last_updated_by = %d,';
         array_push($dataTypes, $current_user->id);

         // Datetime columns.
         $classStart = date('Y-m-d G:i', strtotime($_POST['class_date'].' '.$_POST['class_time']));
         $myQuery .= ' class_start = \''.$classStart.'\',';
         $myQuery .= ' class_end = \''.date('Y-m-d G:i', strtotime($classStart.' +'.$_POST['class_length'].' minutes')).'\',';

         // NULL columns.
         $myQuery .= ' librarian2_name = ';
         if(empty($_POST['librarian2_name']))   { $myQuery .= 'NULL,'; } else { $myQuery .= '%s,'; array_push($dataTypes, $_POST['librarian2_name']); }
         $myQuery .= ' instructor_email = ';
         if(empty($_POST['instructor_email']))  { $myQuery .= 'NULL,'; } else { $myQuery .= '%s,'; array_push($dataTypes, $_POST['instructor_email']); }
         $myQuery .= ' instructor_phone = ';
         if(empty($_POST['instructor_phone']))  { $myQuery .= 'NULL,'; } else { $myQuery .= '%s,'; array_push($dataTypes, $_POST['instructor_phone']); }
         $myQuery .= ' class_description = ';
         if(empty($_POST['class_description'])) { $myQuery .= 'NULL,'; } else { $myQuery .= '%s,'; array_push($dataTypes, $_POST['class_description']); }
         $myQuery .= ' course_number = ';
         if(empty($_POST['course_number']))     { $myQuery .= 'NULL,'; } else { $myQuery .= '%s,'; array_push($dataTypes, $_POST['course_number']); }
         $myQuery .= ' attendance = ';
         if(empty($_POST['attendance']))        { $myQuery .= 'NULL';  } else { $myQuery .= '%d';  array_push($dataTypes, $_POST['attendance']); }

         // If is an update add ID.
         if($id) {
            $myQuery .= ' WHERE id = %d';
            array_push($dataTypes, $id);
         }
         // If it is not an update include owner ID. This will have to be edited when owners can be changed.
         else {
            $myQuery .= ', owner_id = %d';
            array_push($dataTypes, $current_user->id);
         }

         $success = $wpdb->query($wpdb->prepare($myQuery, $dataTypes));

         if(!$id) { $id = $wpdb->insert_id; }


         // Flag management.
         $flagNames = preg_grep('/^flagName\d+/', array_keys($_POST));
         foreach($flagNames as $name) {
            $d = substr($name, -1, 1); // Which flag number are we dealing with?

            // Make sure flagName POST var exists.
            if(!empty($_POST[$name])) {
               $value = (isset($_POST['flagValue'.$d]) && ($_POST['flagValue'.$d] == 'on')) ? 1 : 0;
               // Maybe at some point check to see if $_POST[$name] is valid before submitting.
               $wpdb->replace($this->tables['flags'], array('posts_id' => $id, 'name' => $_POST[$name], 'value' => $value), array('%d', '%s', '%d'));
            }
         }

         if($success !== false) { return $id; } // Returns ID of the effected row.
         else                   { return false; } // Returns false if either update or insert failed.
      }


      /*
         Function: reportsPage
            Allows users to either download CSV data or view the data in a table for reporting
            purposes.

         Outputs:
            HTML for the reports page.

         See Also:
            <generateReport>
      */
      public function reportsPage() {
         if(!current_user_can('edit_posts')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         global $wpdb;
         $this->init($wpdb);

         ?>
         <div class="wrap">
            <h2>Reports</h2>

            <?php
            // Debugging
            if($this->options['debug'] && !empty($_POST)) {
               echo '<div id="message" class="error">';
               echo '<p><strong>POST</strong></p>
               <pre>'.print_r($_POST, true).'</pre>';
               echo '</div>';
            }
            ?>

            <form action="" method="post">
               <table class="form-table">
                  <tr>
                     <th>Primary Librarian <em>(optional)</em></th>
                     <td><select name="librarian_name"><option value=""></option>
                     <?php
                     $name = $wpdb->get_results("SELECT DISTINCT librarian_name FROM ".$this->tables['posts']." ORDER BY librarian_name");

                     foreach($name as $n) {
                        echo '<option value="'.$n->librarian_name.'">'.$n->librarian_name.'</option>';
                     }
                     ?>
                     </select></td>
                  </tr>
                  <tr>
                     <th>Start Date <em>(optional)</em></th>
                     <td><input class="<?= self::SLUG.'-date'; ?>" type="text" name="startDate" /></td>
                  </tr>
                  <tr>
                     <th>End Date <em>(optional)</em></th>
                     <td><input class="<?= self::SLUG.'-date'; ?>" type="text" name="endDate" /></td>
                  </tr>
                  <tr>
                     <th>Options</th>
                     <td><label><input type="radio" name="option" value="file" checked="checked" /> Download File</label><br />
                         <label><input type="radio" name="option" value="report" /> Display Report</label></td>
                  </tr>
               </table>

               <p class="submit">
                  <input type="hidden" name="action" value="<?= self::SLUG; ?>_download_report" />
                  <input type="submit" name="submit" class="button-primary" value="Gimme That Report!" />&nbsp;&nbsp;
                  <input style="cursor: pointer;" type="reset" value="Reset Form" />
               </p>
            </form>

            <?php
            if(isset($_POST['action']) && ($_POST['action'] == self::SLUG.'_download_report') && ($_POST['option'] == 'report')) {
               $this->generateReport(false);
            }
            ?>
         </div>
         <?php
      }


      /*
         Function: generateReport
            Creates and sends reporting data and sends it to user as a CSV file or HTML table output.

         Inputs:
            fileOutput  -  Option for outputting info as a file or HTML table data (defaults to file).

         Outputs:
            CSV report file with respective HTML headers or HTML table data.

         See Also:
            <reportsPage>
      */
      private function generateReport($fileOutput = true) {
         global $wpdb;
         $this->init($wpdb);
         $fileName = $this->options['slug'];
         $query = 'SELECT p.id, librarian_name, librarian2_name, instructor_name, instructor_email, instructor_phone,
                   class_start, class_end, class_location, class_type, audience, class_description, department_group,
                   course_number, attendance, u.display_name as owner, last_updated,
                   u2.display_name as last_updated_by FROM '.$this->tables['posts'].' p JOIN '.$wpdb->users.' u ON p.owner_id = u.ID
                   JOIN '.$wpdb->users.' u2 ON p.last_updated_by = u2.ID';
         $options = array();

         // Check if additional parameters have been given.
         if(!empty($_POST['librarian_name']) || !empty($_POST['startDate']) || !empty($_POST['endDate'])) {
            // Prepare the query with WHERE statement.
            $query .= ' WHERE';

            if(!empty($_POST['librarian_name'])) {
               $query .= ' librarian_name = %s AND';
               array_push($options, $_POST['librarian_name']);
               $fileName .= ' '.preg_replace('/[^a-z]/i', '', $_POST['librarian_name']);
            }
            if(!empty($_POST['startDate'])) {
               $date = date('Y-m-d', strtotime($_POST['startDate']));
               $query .= ' class_start >= %s AND';
               array_push($options, $date.' 00:00:00');
               $fileName .= ' starting '.$date;
            }
            if(!empty($_POST['endDate'])) {
               $date = date('Y-m-d', strtotime($_POST['endDate']));
               $query .= ' class_start <= %s AND';
               array_push($options, $date.' 23:59:59');
               $fileName .= ' ending '.$date;
            }

            // Remove trailing AND from query.
            $query = substr($query, 0, -4);

            // Prepare query.
            $query = $wpdb->prepare($query, $options);
         }
         else {
            $fileName .= ' all';
         }

         $fileName .= '.csv';
         $query .= ' ORDER BY class_start, class_end';
         $result = $wpdb->get_results($query, ARRAY_A);
         $column = $wpdb->get_col_info('name');
         array_push($column, 'flags'); // Manually add flags column.

         // Add flags to each record.
         foreach($result as $i => $v) {
            $flags = $wpdb->get_results('SELECT name, value FROM '.$this->tables['flags'].' WHERE posts_id = '.$v['id'], ARRAY_A);

            // Put values in a temp array to be imploded later.
            $tempA = array();
            foreach($flags as $f) {
               $tempS = $f['value'] ? 'yes' : 'no';
               array_push($tempA, $f['name'].' = '.$tempS);
            }

            array_push($result[$i], implode(', ', $tempA)); // Boom
         }

         // Write data out to CSV file.
         if($result && $fileOutput) {
            $f = fopen('php://output', 'w');
            fputcsv($f, $column);

            foreach($result as $line) {
               fputcsv($f, $line);
            }

            // Send the proper header information for a CSV file.
            header("Content-type: text/csv");
            header("Content-Disposition: attachment; filename=".$fileName);
            header("Pragma: no-cache");
            header("Expires: 0");

            fseek($f, 0);
            fpassthru($f);
            fclose($f);
            exit;
         }
         // Write data out as HTML table.
         else if($result) {
            ?>
            <table id="reportTable" class="widefat">
               <thead>
                  <tr>
                     <?php
                     foreach($column as $c) {
                        // Next two ifs to make the table look a bit better.
                        if($c == 'class_start' || $c == 'class_end') {
                           echo '<th style="min-width:80px;">';
                        }
                        else if($c == 'flags') {
                           echo '<th style="min-width:120px;">';
                        }
                        else {
                           echo '<th>';
                        }

                        echo str_replace('_', ' ', $c);
                        echo '</th>';
                     }
                     ?>
                  </tr>
               </thead>
               <tbody>
                  <?php
                  foreach($result as $line) {
                     echo '<tr>';

                     foreach($line as $l) {
                        echo '<td>'.$l.'</td>';
                     }

                     echo '</tr>';
                  }
                  ?>
               </tbody>
            </table>
            <?php
         }
      }


      /*
         Function: fieldsPage
            Allows manipulation of the adjustable fields.

         Outputs:
            HTML for the fields page.
      */
      public function fieldsPage() {
         if(!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         global $wpdb;
         $this->init($wpdb);

         // Get current fields from database.
         $departmentGroup = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'department_group_values'"));
         $classLocation = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'class_location_values'"));
         $classType = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'class_type_values'"));
         $audience = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'audience_values'"));
         $flags = unserialize($wpdb->get_var("SELECT value FROM ".$this->tables['meta']." WHERE field = 'flag_info'"));

         // Check for form submission and do appropriate action.
         if(isset($_POST[self::SLUG.'_nonce']) && wp_verify_nonce($_POST[self::SLUG.'_nonce'], self::SLUG.'_fields')) {
            $_POST = array_map("trim", $_POST); // Added so that blank fields cannot be saved.

            // Add a department / group field to the database.
            if(!empty($_POST['deptGroupAdd']) && !empty($_POST['deptGroupTB'])) {
               if($departmentGroup) {
                  array_push($departmentGroup, $_POST['deptGroupTB']);
                  natcasesort($departmentGroup);
                  $wpdb->update($this->tables['meta'], array('value' => serialize($departmentGroup)), array('field' => 'department_group_values'));
               }
               else {
                  $departmentGroup = array();
                  array_push($departmentGroup, $_POST['deptGroupTB']);
                  $wpdb->insert($this->tables['meta'], array('field' => 'department_group_values', 'value' => serialize($departmentGroup)));
               }
            }
            // Remove a department / group field from the database.
            else if(!empty($_POST['deptGroupRemove'])) {
               $temp = $_POST['deptGroupSB'] + 1;

               if(!empty($temp)) {
                  unset($departmentGroup[$_POST['deptGroupSB']]);

                  if($departmentGroup) {
                     $wpdb->update($this->tables['meta'], array('value' => serialize($departmentGroup)), array('field' => 'department_group_values'));
                  }
                  else {
                     $wpdb->delete($this->tables['meta'], array('field' => 'department_group_values'));
                  }
               }
            }
            // Add a class location field to the database.
            if(!empty($_POST['classLocAdd']) && !empty($_POST['classLocTB'])) {
               if($classLocation) {
                  array_push($classLocation, $_POST['classLocTB']);
                  natcasesort($classLocation);
                  $wpdb->update($this->tables['meta'], array('value' => serialize($classLocation)), array('field' => 'class_location_values'));
               }
               else {
                  $classLocation = array();
                  array_push($classLocation, $_POST['classLocTB']);
                  $wpdb->insert($this->tables['meta'], array('field' => 'class_location_values', 'value' => serialize($classLocation)));
               }
            }
            // Remove a class location field from the database.
            else if(!empty($_POST['classLocRemove'])) {
               $temp = $_POST['classLocSB'] + 1;

               if(!empty($temp)) {
                  unset($classLocation[$_POST['classLocSB']]);

                  if($classLocation) {
                     $wpdb->update($this->tables['meta'], array('value' => serialize($classLocation)), array('field' => 'class_location_values'));
                  }
                  else {
                     $wpdb->delete($this->tables['meta'], array('field' => 'class_location_values'));
                  }
               }
            }
            // Add a class type field to the database.
            if(!empty($_POST['classTypeAdd']) && !empty($_POST['classTypeTB'])) {
               if($classType) {
                  array_push($classType, $_POST['classTypeTB']);
                  natcasesort($classType);
                  $wpdb->update($this->tables['meta'], array('value' => serialize($classType)), array('field' => 'class_type_values'));
               }
               else {
                  $classType = array();
                  array_push($classType, $_POST['classTypeTB']);
                  $wpdb->insert($this->tables['meta'], array('field' => 'class_type_values', 'value' => serialize($classType)));
               }
            }
            // Remove a class type field from the database.
            else if(!empty($_POST['classTypeRemove'])) {
               $temp = $_POST['classTypeSB'] + 1;

               if(!empty($temp)) {
                  unset($classType[$_POST['classTypeSB']]);

                  if($classType) {
                     $wpdb->update($this->tables['meta'], array('value' => serialize($classType)), array('field' => 'class_type_values'));
                  }
                  else {
                     $wpdb->delete($this->tables['meta'], array('field' => 'class_type_values'));
                  }
               }
            }
            // Add an audience field to the database.
            if(!empty($_POST['audienceAdd']) && !empty($_POST['audienceTB'])) {
               if($audience) {
                  array_push($audience, $_POST['audienceTB']);
                  natcasesort($audience);
                  $wpdb->update($this->tables['meta'], array('value' => serialize($audience)), array('field' => 'audience_values'));
               }
               else {
                  $audience = array();
                  array_push($audience, $_POST['audienceTB']);
                  $wpdb->insert($this->tables['meta'], array('field' => 'audience_values', 'value' => serialize($audience)));
               }
            }
            // Remove an audience field from the database.
            else if(!empty($_POST['audienceRemove'])) {
               $temp = $_POST['audienceSB'] + 1;

               if(!empty($temp)) {
                  unset($audience[$_POST['audienceSB']]);

                  if($audience) {
                     $wpdb->update($this->tables['meta'], array('value' => serialize($audience)), array('field' => 'audience_values'));
                  }
                  else {
                     $wpdb->delete($this->tables['meta'], array('field' => 'audience_values'));
                  }
               }
            }
            // Adds flag options to the database.
            else if(!empty($_POST['flagSave'])) {
               $flags = array(); // Don't want leftovers...
               $flagNames = preg_grep('/^flagName\d+/', array_keys($_POST));

               foreach($flagNames as $name) {
                  $i = substr($name, -1, 1); // Which flag number are we dealing with?

                  // Make sure flagName POST var exists.
                  if(!empty($_POST[$name])) {
                     $flags[$_POST[$name]] = $_POST['flagEnabled'.$i];
                  }
               }

               $wpdb->replace($this->tables['meta'], array('field' => 'flag_info', 'value' => serialize($flags)));
            }
         } // End form submission.


         ?>
         <div class="wrap">
            <h2>Fields</h2>

            <?php
            // Debugging.
            if($this->options['debug'] && !empty($_POST)) {
               echo '<div id="message" class="error">';

               if(!empty($_POST)) {
                  echo '<p><strong>POST</strong></p>
                  <pre>'.print_r($_POST, true).'</pre>';
               }

               echo '</div>';
            }
            ?>

            <form action="" method="post">
               <h3>Department/Group</h3>
               <input name="deptGroupTB" type="text" />
               <input name="deptGroupAdd" type="submit" class="button-secondary" value="Add Dept/Group" /><br /><br />
               <select id="deptGroupSB" name="deptGroupSB" size="<?= count($departmentGroup) < 10 ? count($departmentGroup) : '10'; ?>">
                  <?php
                  foreach($departmentGroup as $i => $x) {
                     echo '<option value="'.$i.'">'.$x.'</option>';
                  }
                  ?>
               </select><br /><br />

               <input name="deptGroupRemove" type="submit" class="button-secondary" value="Remove Dept/Group" />
               <?php wp_nonce_field(self::SLUG.'_fields', self::SLUG.'_nonce'); ?>
            </form>

            <form action="" method="post">
               <h3>Class Location</h3>
               <input name="classLocTB" type="text" />
               <input name="classLocAdd" type="submit" class="button-secondary" value="Add Class Location" /><br /><br />
               <select id="classLocSB" name="classLocSB" size="<?= count($classLocation) < 10 ? count($classLocation) : '10'; ?>">
                  <?php
                  foreach($classLocation as $i => $x) {
                     echo '<option value="'.$i.'">'.$x.'</option>';
                  }
                  ?>
               </select><br /><br />

               <input name="classLocRemove" type="submit" class="button-secondary" value="Remove Class Location" />
               <?php wp_nonce_field(self::SLUG.'_fields', self::SLUG.'_nonce'); ?>
            </form>

            <form action="" method="post">
               <h3>Class Type</h3>
               <input name="classTypeTB" type="text" />
               <input name="classTypeAdd" type="submit" class="button-secondary" value="Add Class Type" /><br /><br />
               <select id="classTypeSB" name="classTypeSB" size="<?= count($classType) < 10 ? count($classType) : '10'; ?>">
                  <?php
                  foreach($classType as $i => $x) {
                     echo '<option value="'.$i.'">'.$x.'</option>';
                  }
                  ?>
               </select><br /><br />

               <input name="classTypeRemove" type="submit" class="button-secondary" value="Remove Class Type" />
               <?php wp_nonce_field(self::SLUG.'_fields', self::SLUG.'_nonce'); ?>
            </form>

            <form action="" method="post">
               <h3>Audience</h3>
               <input name="audienceTB" type="text" />
               <input name="audienceAdd" type="submit" class="button-secondary" value="Add Audience" /><br /><br />
               <select id="audienceSB" name="audienceSB" size="<?= count($audience) < 10 ? count($audience) : '10'; ?>">
                  <?php
                  foreach($audience as $i => $x) {
                     echo '<option value="'.$i.'">'.$x.'</option>';
                  }
                  ?>
               </select><br /><br />

               <input name="audienceRemove" type="submit" class="button-secondary" value="Remove Audience" />
               <?php wp_nonce_field(self::SLUG.'_fields', self::SLUG.'_nonce'); ?>
            </form>

            <form action="" method="post">
               <h3>Flags</h3>

               <?php
               $i = 1;
               foreach($flags as $name => $value) {
                  echo '<p><label>Name: <input type="text" name="flagName'.$i.'" value="'.$name.'" /></label>
                        <label>Enabled <input type="radio" name="flagEnabled'.$i.'" value="1" '; if($value) { echo 'checked="checked"'; } echo' /></label>
                        <label>Disabled <input type="radio" name="flagEnabled'.$i.'" value="0" '; if(!$value) { echo 'checked="checked"'; } echo' /></label></p>';

                  $i++;
               }
               ?>

               <p><label>Name: <input type="text" name="flagName<?= $i; ?>" /></label>
               <label>Enabled <input type="radio" name="flagEnabled<?= $i; ?>" value="1" /></label>
               <label>Disabled <input type="radio" name="flagEnabled<?= $i; ?>" value="0" /></label></p>

               <input name="flagSave" type="submit" class="button-secondary" value="Save Flags" />
               <?php wp_nonce_field(self::SLUG.'_fields', self::SLUG.'_nonce'); ?>
            </form>
         </div>
         <?php
      }


      /*
         Function: settingsPage
            Controls what shows up on the settings page of this plugin.

         Outputs:
            HTML for LIR settings.

         See Also:
            <sanitizeSettings>
      */
      public function settingsPage() {
         if(!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
         }

         $this->init();

         ?>
         <div class="wrap">
            <h2>Settings</h2>

            <?php
            if(!empty($_GET['settings-updated'])) {
               echo '<div id="message" class="updated '.self::SLUG.'-fade">';
               echo 'The settings have been updated!';
               echo '</div>';
            }
            ?>

            <form method="post" action="options.php">
               <?php settings_fields(self::OPTIONS_GROUP); ?>
               <table class="form-table">
                  <tr>
                     <th scope="row">Plugin Name</th>
                     <td><input type="text" name="<?= self::OPTIONS.'[name]'; ?>" value="<?= $this->options['name']; ?>" /></td>
                  </tr>
                  <tr>
                     <th scope="row">Plugin Slug</th>
                     <td><input type="text" name="<?= self::OPTIONS.'[slug]'; ?>" value="<?= $this->options['slug']; ?>" /></td>
                  </tr>
                  <tr>
                     <th scope="row">Class Length Interval<br /><em class="smaller">(in minutes)</em></th>
                     <td><input type="number" name="<?= self::OPTIONS.'[intervalLength]'; ?>" value="<?= $this->options['intervalLength'] ?>" /></td>
                  </tr>
                  <tr>
                     <th scope="row">Number of Intervals</th>
                     <td><input type="number" name="<?= self::OPTIONS.'[intervalAmount]'; ?>" value="<?= $this->options['intervalAmount'] ?>" /></td>
                  </tr>
                  <tr>
                     <th scope="row">Debugging<br /><em class="warning smaller">(this produces a lot of output)</em></th>
                     <td><input type="checkbox" name="<?= self::OPTIONS.'[debug]'; ?>" <?php checked($this->options['debug'], 'on'); ?> /> Enabled</td>
                  </tr>
               </table>

               <p class="submit">
                  <input type="submit" class="button-primary" value="Save Changes" />
               </p>
            </form>
         </div>
         <?php
      }


      /*
         Function: sanitizeSettings
            Sanitizes all inputs that are run through the settings page. Also adds version so it doesn't
            get removed from the options.

         Inputs:
            input  -  Array of options from the LIR settings page.

         Returns:
            array  -  Array of sanitized options.

         See Also:
            <settingsPage>
      */
      public function sanitizeSettings($input) {
         $this->init();

         // Remove spaces!
         $input = array_map("trim", $input);

         $input['version'] = $this->options['version'];
         $input['debug'] = ($input['debug'] == 'on') ? 'on' : '';
         $input['name'] = (empty($input['name'])) ? self::$defaultOptions['name'] : sanitize_text_field($input['name']);
         $input['slug'] = (empty($input['slug'])) ? self::$defaultOptions['slug'] : sanitize_text_field($input['slug']);
         $input['intervalLength'] = (absint($input['intervalLength']) < 1) ? self::$defaultOptions['intervalLength'] : absint($input['intervalLength']);
         $input['intervalAmount'] = (absint($input['intervalAmount']) < 1) ? self::$defaultOptions['intervalAmount'] : absint($input['intervalAmount']);

         return $input;
      }


      /*
         Function: emailReminders
            Sends out email reminders to users who have a class that does not have the attendance
            filled out that eneded before today.

         Outputs:
            HTML emails through WordPress.

         See Also:
            <setMailToHtml>
      */
      public function emailReminders() {
         global $wpdb;
         $this->init($wpdb);

         add_filter('wp_mail_content_type', array(&$this, 'setMailToHtml')); // So we can send the email in HTML.

         $results = $wpdb->get_results('SELECT id, department_group, course_number, owner_id FROM '.$this->tables['posts'].' WHERE DATE(class_end) < DATE(NOW()) AND attendance IS NULL', OBJECT);

         foreach($results as $r) {
            $uInfo = $wpdb->get_row('SELECT user_email, display_name FROM '.$wpdb->users.' WHERE ID = '.$r->owner_id, OBJECT);
            
            $message  = '<p>Greetings '.$uInfo->display_name.',</p>';
            $message .= '<p>This email notificaion serves as a reminder that you need to fill in the number of attending students for the following completed class:</p>';
            $message .= '<p><a href="'.admin_url('admin.php?page='.self::SLUG.'-add-a-class&edit='.$r->id).'">'.$r->department_group;
            $message .= $r->course_number ? ' '.$r->course_number : '';
            $message .= '</a></p>';
            $message .= '<p>Warmly,<br />'.$this->options['slug'].'</p>';

            wp_mail($uInfo->user_email, 'REMINDER: '.$this->options['name'], $message); // From nobody (may have to look into this later)?
         }

         remove_filter('wp_mail_content_type', array(&$this, 'setMailToHtml')); // Apparently there is a bug and this needs to happen (probably not a bad idea anyway).
      }


      /*
         Function: setMailToHtml
            Changes the email type to text/html.

         Returns:
            Text/html type.

         See Also:
            <emailReminders>
      */
      public function setMailToHtml($type) {
         return 'text/html';
      }


      /*
         Function: updateNotificationCount
            Updates the notification count in the menu when called.

         Outputs:
            Updated WordPress menu content.
      */
      private function updateNotificationCount() {
         global $wpdb, $current_user, $menu;
         $this->init($wpdb);
         $position = NULL;

         // Find LIR menu item.
         foreach($menu as $k => $m) {
            // I believe $m[2] is the ID of the menu item, hence self::SLUG instead of $this->options['slug'].
            if($m[2] == self::SLUG) { 
               $position = $k;
               break;
            } 
         }

         if($position == NULL) { return; }

         // Get count of classes that need to be updated.
         $count = $wpdb->get_var('SELECT COUNT(*) FROM '.$this->tables['posts'].' WHERE DATE(class_end) < DATE(NOW()) AND attendance IS NULL AND owner_id = '.$current_user->id);
         // If 0 notifications we still want to update the menu, just in case (ex: last notification was handled and menu needed to be updated to reflect that 1 -> 0).
         $notifications = $count ? ' <span class="update-plugins count-'.$count.'"><span class="update-count">'.$count.'</span></span>' : '';
         $menu[$position][0] = $this->options['slug'].$notifications; // Rewrite the entire name in case this function is called multiple times.
      }


      /*
         Function: setTimeZone
            Sets the default timezone that PHP uses with the date function. This will not
            work correctly if the GMT offset is used and DST is in effect.
      */
      private function setTimeZone() {
         $zoneString = get_option('timezone_string'); // Is the timezone in here?
         $gmtOffset = get_option('gmt_offset'); // How about in here?

         if(!empty($zoneString)) {
            date_default_timezone_set($zoneString);
         }
         else if(!empty($gmtOffset)) {
            // This will not work correctly if the timezone uses DST (during DST).
            date_default_timezone_set(timezone_name_from_abbr(null, $gmtOffset * 3600, 0));
         }
         // else... what now?
      }


      /*
         Function: easterEgg
            Test filter function that adds text to the end of content.

         Inputs:
            input  -  A string containing content.

         Returns:
            string  -  A modified string of content.
      */
      public function easterEgg($input = '') {
         return $input . "<p style='text-align:right;'><em>I am Lrrr, ruler of the planet Omicron Persei 8!</em></p>";
      }
   }


   $LIR = new LIR();  // Create object only if class did not already exist.
}
