/*
   Script: js/admin.js
      JavaScript file used for adding functionality to the LIR plugin.
      
   About: Plugin
      Library Instruction Recorder

   About: License
      GPLv3


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


/*
   Function: jQuery(function($){})
      On document load.
   
   Inputs:
      $  -  Sets the jQuery object to be $ since jQuery is running in no conflict mode.
*/
jQuery(function($) {
   // Initializes DatePicker for date fields.
   // The format could potentially be an option at some point.
   $('.LIR-date').datepicker({
      dateFormat: 'm/d/yy'
   });

   // Stops links from firing.
   $('.stopLinkFire').click(function(e){
      e.preventDefault();
   });

   // Fades out containers that have the class LIR-fade.
   $('.LIR-fade').each(function(){
      $(this).delay(2000).fadeOut(2000);
   });

   // DataTables for the report table.
   $('#reportTable').DataTable({
      scrollX: true
   });

   // DataTables for the class listing (default page) table.
   $('#classListingTable').DataTable({
      // Disable sorting of first column.
      aoColumnDefs: [{
         bSortable: false,
         aTargets: [0, 6]
      }],
      // Initial sorting off (sorted with query instead).
      aaSorting: [],
      // No records message.
      language: {
         zeroRecords: 'No classes are currently available in this view.'
      }
   });
});


// Sets up $j for jQuery no conflict mode for the following functions.
var $j = jQuery.noConflict();


/*
   Function: removeClass
      Displays a prompt for the removal of a class.
   
   Inputs:
      url  -  A URL to forward the browser to if the confirm box is true.
   
   Outputs:
      A confirm box.
*/
function removeClass(url) {
   if(confirm("Are you sure you want to remove this class?")) {
      window.location.href = url;
   }
}


/*
   Function: showDetails
      Constructs and shows the details of a class. Uses jQueryUI dialog to handle the display.
   
   Inputs:
      id  -  The ID of the class to display.
   
   Outputs:
      A jQueryUI dialog box containing class details.
*/
function showDetails(id) {
   var $table = $j('<table></table>').attr({cellspacing: 0, cellpadding: 0});
   
   $j('.'+id+' > .otherDetails > span').each(function() {
      if($j(this).attr('name')) {
         field = $j(this).attr('name').replace(/-/g, '/').replace(/_/g, ' ');
      }
      else {
         field = '';
      }
      
      $j($table).append('<tr><td>'+field+'</td><td>'+$j(this).html()+'</td></tr>');
   });
   
   $j($table).find('tr:last').attr('class', 'last'); // Apply class to make it look pretty.
   $table = $j('<div></div>').attr('id', 'LIR-popup').append($table);
   
   $j($table).dialog({
      title: 'Other Details',
      width: 360
   });
}
