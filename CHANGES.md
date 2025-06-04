## 0.8.13

-   Adding the Entrata property codes used into the request to the Rent Fetch API, to allow for easier access for troubleshooting (this was left out in error)

## 0.8.12

-   Bugfix: incorrect date handling when pulling in availability for tours from Entrata.

## 0.8.11

-   Removing capability for unit editing from core

## 0.8.10

-   Minor markup updates to align with new styles

## 0.8.9

-   Potential fix for the Entrata forms not properly scheduling an event when one is expected.

## 0.8.8

-   More functionality for Entrata forms, changed how we submit tours.

## 0.8.7

-   Added numerous new parameters for the form, allowing for more customization of that (confirmation message, redirect url, etc.)

## 0.8.6

-   Added more debug information for forms to diagnose issues.

## 0.8.5

-   Remove the form on succesful submission
-   Move the success message outside of the form
-   Better validation for the phone number field
-   Fixing timezones in the Entrata display of times for the form

## 0.8.4

-   Bugfix: in the Entrata form submission, if the code is 200, the message isn't set. Fixing the exception.
-   Hiding the lead_source field in the same.

## 0.8.3

-   Adding capabilities for Entrata to use a lead source.

## 0.8.2

-   Bugfix: the forms were getting the response code from wp_remote_get, rather than from the response body. We've updated that and fixed a bug related to Entrata's weird need to have us give them the current date and time, but only in MDT, which is curiously not documented within their sendLeads API docs.

## 0.8.1

-   Removing availability from floorplans which no longer appear in the Yardi API.

## 0.8

-   Adding new capability for sending information into the Entrata API with two new shortcodes and a new form.

## 0.7.2

-   Adding ability for the Entrata API to update the availability date on the floorplan level (if this isn't updated in addition to being updated on the unit level then the date-based filtering for the properties fails to work properly)

## 0.7.1

-   Improvements to reporting to the RF API.
-   Bugfix: we weren't properly reporting to the API when an integration has data, but is not enabled, resulting in data that looks incorrect.
-   Bugfix: we weren't reporting Entrata data to the API at all. That's fixed.

## 0.7

-   Adding the ability to sync individual properties from the backend on the properties, floorplans, or units pages.

## 0.6.5

-   Due to changes in function between Yardi v1 API and Yardi v2 API, we'd missed syncing the availability dates on the floorplan level, which resulted in degraded search functionality in the RentFetch core. This update rectifies that as a hotfix. However, there's new complexity in terms of how we treat waitlisted units, and we'll update that in a later patch.

## 0.6.4

-   Add building name and floorplan number to the Entrata sync. This data doesn't appear to exist in the Yardi API, which is sad.

## 0.6.3

-   Remove Surecart functionality, as we'll no longer be using them for licensing
-   Add ability to build "Apply Now" links for units that link to Entrata's service
-   Remove dated units when syncing with the Entrata v2 API.

## 0.6.2

-   Adding functionality to allow for automatically removing dated unit data that no longer appears in the Yardiv2 API.
-   Fixed php notice.

## 0.6.1

-   Removing the option for sync timeline, adding new default at 24 hours with a filter to allow themes/plugins to change it if there's a real need.

## 0.6

-   Adding Entrata sync
-   Minor fixes to the Yardi v2 API sync

## 0.5.3

-   When a floorplan in Rent Manager (unit type, as they call it) has no beds or baths set, we're not going to sync that one down.
-   When a unit in Rent Manager has no price set, we're not going to sync that one down.

## 0.5.2

-   Add capability to Rent Manager sync to remove properties, floorplans, and units when the associated property is no longer enabled for sync.

## 0.5.1

-   Add capabilities for Yardi v2 API

## 0.4.25

-   Remove meta for Brindle's API key (we'll pass this in the RF API moving forward, so that we don't need to give it out)
-   Fixing several potential fatal errors that can happen if the Rent Manager unittypes or units API calls fail.

## 0.4.24

-   Setting the transient after hitting the RentFetch API regardless of whether there's a successful response, as this function can trigger multiple times per pageload (we risk DDOSing ourselves if this isn't done).

## 0.4.23

-   Adding information to the RentFetch API (includes some basic site information, along with capabilities of sending back necessary information for syncing for Rent Manager and v2 of the Yardi API).

## 0.4.22

-   We don't need as much information as we were gathering. We're removing some of the new fields.

## 0.4.21

-   Adding fields for Yardi API v2

## 0.4.20

-   Fixing more minor errors in Rent Manager, mainly around the API not returning values that we expect in cases where the client hasn't entered those values.
-   Adding capability to detect external IP address using an API for use in RM whitelisting.

## 0.4.16

-   Fixing a fatal error when Rent Manager data loads the very first time.

## 0.4.15

-   Removing notices for license activation, since that's currently unused (it's coming!)

## 0.4.14

-   A few changes to match up with the RF style project
-   Rent Manager options added
-   Syncing for Rent Manager properties, floorplans (unit types), and units
-   Fixing a fatal error when settign saved with Rent Manager disabled
-   Code standard improvements
-   Getting property images from Rent Manager
-   Fix for empty dollar amount on floorplans from Rent Manager

## 0.4.13

-   Adding new license levels

## 0.4.12

-   Removing unused options for Entrata and Appfolio, since those integrations aren't actually set up yet.

## 0.4.11

-   Adding a couple of escapes to the Yardi data when we're saving floorplans.
-   Adding an action on plugin deactivation to cancel ongoing sync actions, so that we avoid situations where those actions attempt to run while Action Scheduler is not active.

## 0.4.10

-   Adding sync term settings

## 0.4.9

-   Add release.json for Surecart
-   Update the Surecart WordPress SDK

## 0.4.8

-   Bugfix: updating the name of rfs_yardi_delete_orphans to rfs_yardi_do_delete_orphans for purposes of removing this action from the schedule when the sync is paused. This was missed in an earlier update, and the bad thing that could happen here is unexpected reversions to a previous iteration of the property list in certain situations.

## 0.4.7

-   Using the 'MadeReadyDate' instead of the 'AvailabilityDate' in RealPage, as those dates match more closly with the RealPage availability site.

## 0.4.6

-   Removing test code to sync an invididual property. That really doesn't belong in the core plugin commented out (NOTE: no private data was included in this, just a property ID and the name of the integration; API keys were always stored in the database, and were never in the codebase of this plugin).
-   Adding a new RealPage API for units, as the List units API is only showing currently-available units and we want to ensure that we have some future-available units in there as well.
-   Reworking all functions related to the updating of floorplans based on the units API information (with the new units GetByProperty API)

## 0.4.5

-   Bugfix: Realpage sites with multiple properties could mistakenly see units removed due to a regression in 0.4.3.

## 0.4.3

-   Fixing a couple of php notices that could occur on units when no RentMatrix exist in the Realpage sync
-   Adding a function to the RealPage sync to check for units that are no longer in the API and delete those

## 0.4.2

-   Updating Yardi sync to pull in unit-level amenities

## 0.4.1

-   Updating RealPage sync to pull unit information.
-   Updating RealPage sync to bubble unit pricing data up to the floorplan level.
-   Starting on code standard review.

## 0.4.0

-   Adding update capability for the plugin (for Rent Fetch Sync).

## 0.3.5

-   Updating prefixes and page names

## 0.3.4

-   Fixed an issue where properties, units, and floorplans weren't being properly deleted when the property ID no longer appeared in the settings

## 0.3.3

-   Bugfix: The Realpage API returns an array of arrays for units when there are multiple for properties, but inconsistently it returns a single array (not nested) there's just one. This was causing our logic to fail when saving availability data. Added code to detect this and convert it into an array of arrays.

## 0.3.2

-   Bugfix: Floorplans not saving their available units when being synced from RealPage
-   Bugfix: Floorplans not saving their available units as 0 when there are no units in the unitslist API (null value breaks some searches)

## 0.3

-   Adding Surecart functionality

## 0.2

-   Adding base functionality for RealPage (floorplans sync, units sync pulls in units but is not yet updating meta information)

## 0.1.3

-   Removing a few unused fields from syncing

## 0.1.2

-   Fixing a bug where we were reading the old names for some of the options (and therefore it wasn't working)

## 0.1.1

-   Adding the tables check, since we need that in this plugin

## 0.1

-   Initial version
