## 0.12.4

- Updating hooks to match the new RF pattern for subpages in the General settings.

## 0.12.3

- Security hardening: escaped Rent Manager settings output in wp-admin to prevent unsafe rendering of option values.
- Security hardening: sanitized Rent Manager properties data before storing it, including support for both list and single-object API response shapes.
- Security hardening: removed unsafe HTML concatenation in frontend form submission handling and switched to safe text-node rendering for success/error messages.
- Security hardening: escaped and validated Entrata availability data before rendering date/time UI in the browser.
- Security hardening: sanitized request metadata (`REMOTE_ADDR`, `HTTP_USER_AGENT`) before storing form entry meta.
- Security hardening: added write-time sanitization for externally sourced unit/property/image meta in Yardi/Entrata/Rent Manager sync flows.
- Minor hardening: escaped sync metabox label text output in wp-admin.

## 0.12.2

- Removing unnecessary console logging.

## 0.12.1

- Using our own proxy instead of the RentManager API.

## 0.12

- Adding capabilities to use Yardi's createlead and updatelead endpoints for our native forms

## 0.11.11

- Yardi: Adding office hours on the property level to the sync.

## 0.11.10

- Entrata: improving handling of units when no units at all are enabled for a property
- Entrata; improving our handling (removal) of floorplans that no longer appear in the API

## 0.11.9

- Adding capabilities for annotating when we're receiving 304 responses from Yardi and handling that correctly (by not updating anything)
- Updating the sync to stop asking the user whether to refresh. There's really only one right answer - we'll just do it and then show a notice that it was successful.
- Updating the RentManager sync to remove unit_types that have been removed from the API. This is an issue that became more visible when we added all of our backend debugging.

## 0.11.7

- Adding sync highlighting to RentManager
- Double-checking units to make sure that RM units no longer in the RM API are being removed.

## 0.11.6

- Better checks for whether Gravity Forms is active

## 0.11.4

- Reinstating functionality to delete properties, floorplans and units for Yardi when they don't appear in the box for syncing. This was removed with v1 of the API.

## 0.11.3

- Changing how we treat 204 responses from the Yardi v2 apartmentavailability API.
- Removing stale data from our stored API repsponses for Yardi
- More rubust checks for which specific units should be retained when units are moved between floorplans.

## 0.11.2

- Minor updates to avoid showing two different pieces of data for the "last sync" on the floorplan and unit level. We've changed the name of this over time, and some old data shows in a way we'd prefer it not. This is a minor update that doesn't impact syncing in any way.

## 0.11.1

- Yardi now does not always give us correct information in their apartmentavailability API. It periodically allows this to be accessed, but returns an empty response if we query more than every 15 minutes (or similar). We already avoid making changes to the units when we get empty data back from Yardi, but we previously relied on that data to fix a second inconsistency in the Yardi data, namely that their floorplans API sometimes doesn't give correct unit availability numbers. We're updating to take the number of units we currently have on the site for that floorplan rather than relying on the API for that information (since yardi is rate-limiting that data source).

## 0.11

- Removal of Yardi v1 API legacy code (this shouldn't be running on any site)
- Adding better functionality to clean json that's coming from the various APIs, since Yardi seems insistent on giving us broken JSON (usually quotation marks within JSON values)

## 0.10.4

- Added logging when we don't have required Entrata API information to specify which piece of information we're missing.

## 0.10.2

- Fixing an error with rentfetchFormAjax coming back undefined.
- Adding the ability to log form entries
- Improvements to form error handling (Entrata has a LOT of different ways they return error codes; we found at least four of them through testing, and those have been added to both the frontend and the backend logging).

## 0.10.1

- Updating Rentmanager unit types API endpoint to accommodate their API being broken (they're fixing).

## 0.10

- Removing RealPage
- Adding Gforms integration that allows the cookie value from wordpress*rentfetch_lead_source to automatically be passed into \_every* gravity form entry and notification, if set. Please note that this functionality requires that someone re-save each form again before the field will fully activate.

## 0.9.2

- Adding option to disable transients (just a minor markup change, the actual functionality is in RF)
- Preventing extra queries on every pageload, which should result in performance improvements

## 0.9.1

- Minor update due to version mismatch.

## 0.9

- We found that there are instances on some caching where the lead_source component of the tour and contact forms was getting cached, so we moved the setting of that value to client-side instead, to avoid having that cached.
- Minor fixes to nomenclature (one of the javascript files wasn't named prefixed with rentfetch- which might be confusing for debugging)
- Added debugging to console for the lead source to denote what's being set and the source of that (e.g. "lead_source set to 393 via cookie, etc.)
- Storing all relevant API responses in meta so that we don't have to guess at what the API told us more recently (or more likely, doing manual requests in postman)
- Updating the cookie we use for lead sources to 'wordpress_rentfetch_lead_source' to match existing likely host Fastly caching exclusions.
- LOTS of changes around how we display the sync button, and the information associated with that.

## 0.8.18

- Add cookie-based lead source persistence: the frontend now stores the captured `lead_source` parameter in a sitewide cookie named `rentfetch_lead_source` for 30 days.
- JavaScript now uses the cookie as a fallback when session storage doesn't contain the tracking parameter, ensuring outgoing external links still receive the lead source parameter.
- Shortcode update: `rfs_output_form()` now prefers an explicit `?lead_source` URL parameter, otherwise will fall back to the `rentfetch_lead_source` cookie (which overrides the shortcode `lead_source` attribute if present). This ensures cookie-based values do not overwrite explicit URL parameters.

## 0.8.17

- Compatibility fix: Popup Maker seems to do something a little odd with add_meta_boxes, causing our addition of the units metabox to run before the RF plugin is fully loaded. The result of this is an error on PUM pages on the site, which can be fixed when RFS is disabled. Adding more specific logic to make sure that we're only loading the meta boxes for the units when we're actualy on a units page in the admin.

## 0.8.16

- Entrata forms now uses EITHER the sendLeads or updateLeads API to send in the lead. Now, when someone submits a form, we look use getLeads to figure out if someone with this email address has ever had a lead with this particular property, then choose which API to use and submit it.

## 0.8.15

- Fixing javascript UTC time zone bug that was resulting in us sending the wrong date into the Entrata API (off by 1 day, so we were always sending the _day before_)

## 0.8.14

- Adding labels to the form that display when appropriate for dates/times
- Adding the blaze slider to the dates and configuring that such that it should work across the board on all devices.

## 0.8.13

- Adding the Entrata property codes used into the request to the Rent Fetch API, to allow for easier access for troubleshooting (this was left out in error)

## 0.8.12

- Bugfix: incorrect date handling when pulling in availability for tours from Entrata.

## 0.8.11

- Removing capability for unit editing from core

## 0.8.10

- Minor markup updates to align with new styles

## 0.8.9

- Potential fix for the Entrata forms not properly scheduling an event when one is expected.

## 0.8.8

- More functionality for Entrata forms, changed how we submit tours.

## 0.8.7

- Added numerous new parameters for the form, allowing for more customization of that (confirmation message, redirect url, etc.)

## 0.8.6

- Added more debug information for forms to diagnose issues.

## 0.8.5

- Remove the form on succesful submission
- Move the success message outside of the form
- Better validation for the phone number field
- Fixing timezones in the Entrata display of times for the form

## 0.8.4

- Bugfix: in the Entrata form submission, if the code is 200, the message isn't set. Fixing the exception.
- Hiding the lead_source field in the same.

## 0.8.3

- Adding capabilities for Entrata to use a lead source.

## 0.8.2

- Bugfix: the forms were getting the response code from wp_remote_get, rather than from the response body. We've updated that and fixed a bug related to Entrata's weird need to have us give them the current date and time, but only in MDT, which is curiously not documented within their sendLeads API docs.

## 0.8.1

- Removing availability from floorplans which no longer appear in the Yardi API.

## 0.8

- Adding new capability for sending information into the Entrata API with two new shortcodes and a new form.

## 0.7.2

- Adding ability for the Entrata API to update the availability date on the floorplan level (if this isn't updated in addition to being updated on the unit level then the date-based filtering for the properties fails to work properly)

## 0.7.1

- Improvements to reporting to the RF API.
- Bugfix: we weren't properly reporting to the API when an integration has data, but is not enabled, resulting in data that looks incorrect.
- Bugfix: we weren't reporting Entrata data to the API at all. That's fixed.

## 0.7

- Adding the ability to sync individual properties from the backend on the properties, floorplans, or units pages.

## 0.6.5

- Due to changes in function between Yardi v1 API and Yardi v2 API, we'd missed syncing the availability dates on the floorplan level, which resulted in degraded search functionality in the RentFetch core. This update rectifies that as a hotfix. However, there's new complexity in terms of how we treat waitlisted units, and we'll update that in a later patch.

## 0.6.4

- Add building name and floorplan number to the Entrata sync. This data doesn't appear to exist in the Yardi API, which is sad.

## 0.6.3

- Remove Surecart functionality, as we'll no longer be using them for licensing
- Add ability to build "Apply Now" links for units that link to Entrata's service
- Remove dated units when syncing with the Entrata v2 API.

## 0.6.2

- Adding functionality to allow for automatically removing dated unit data that no longer appears in the Yardiv2 API.
- Fixed php notice.

## 0.6.1

- Removing the option for sync timeline, adding new default at 24 hours with a filter to allow themes/plugins to change it if there's a real need.

## 0.6

- Adding Entrata sync
- Minor fixes to the Yardi v2 API sync

## 0.5.3

- When a floorplan in Rent Manager (unit type, as they call it) has no beds or baths set, we're not going to sync that one down.
- When a unit in Rent Manager has no price set, we're not going to sync that one down.

## 0.5.2

- Add capability to Rent Manager sync to remove properties, floorplans, and units when the associated property is no longer enabled for sync.

## 0.5.1

- Add capabilities for Yardi v2 API

## 0.4.25

- Remove meta for Brindle's API key (we'll pass this in the RF API moving forward, so that we don't need to give it out)
- Fixing several potential fatal errors that can happen if the Rent Manager unittypes or units API calls fail.

## 0.4.24

- Setting the transient after hitting the RentFetch API regardless of whether there's a successful response, as this function can trigger multiple times per pageload (we risk DDOSing ourselves if this isn't done).

## 0.4.23

- Adding information to the RentFetch API (includes some basic site information, along with capabilities of sending back necessary information for syncing for Rent Manager and v2 of the Yardi API).

## 0.4.22

- We don't need as much information as we were gathering. We're removing some of the new fields.

## 0.4.21

- Adding fields for Yardi API v2

## 0.4.20

- Fixing more minor errors in Rent Manager, mainly around the API not returning values that we expect in cases where the client hasn't entered those values.
- Adding capability to detect external IP address using an API for use in RM whitelisting.

## 0.4.16

- Fixing a fatal error when Rent Manager data loads the very first time.

## 0.4.15

- Removing notices for license activation, since that's currently unused (it's coming!)

## 0.4.14

- A few changes to match up with the RF style project
- Rent Manager options added
- Syncing for Rent Manager properties, floorplans (unit types), and units
- Fixing a fatal error when settign saved with Rent Manager disabled
- Code standard improvements
- Getting property images from Rent Manager
- Fix for empty dollar amount on floorplans from Rent Manager

## 0.4.13

- Adding new license levels

## 0.4.12

- Removing unused options for Entrata and Appfolio, since those integrations aren't actually set up yet.

## 0.4.11

- Adding a couple of escapes to the Yardi data when we're saving floorplans.
- Adding an action on plugin deactivation to cancel ongoing sync actions, so that we avoid situations where those actions attempt to run while Action Scheduler is not active.

## 0.4.10

- Adding sync term settings

## 0.4.9

- Add release.json for Surecart
- Update the Surecart WordPress SDK

## 0.4.8

- Bugfix: updating the name of rfs_yardi_delete_orphans to rfs_yardi_do_delete_orphans for purposes of removing this action from the schedule when the sync is paused. This was missed in an earlier update, and the bad thing that could happen here is unexpected reversions to a previous iteration of the property list in certain situations.

## 0.4.7

- Using the 'MadeReadyDate' instead of the 'AvailabilityDate' in RealPage, as those dates match more closly with the RealPage availability site.

## 0.4.6

- Removing test code to sync an invididual property. That really doesn't belong in the core plugin commented out (NOTE: no private data was included in this, just a property ID and the name of the integration; API keys were always stored in the database, and were never in the codebase of this plugin).
- Adding a new RealPage API for units, as the List units API is only showing currently-available units and we want to ensure that we have some future-available units in there as well.
- Reworking all functions related to the updating of floorplans based on the units API information (with the new units GetByProperty API)

## 0.4.5

- Bugfix: Realpage sites with multiple properties could mistakenly see units removed due to a regression in 0.4.3.

## 0.4.3

- Fixing a couple of php notices that could occur on units when no RentMatrix exist in the Realpage sync
- Adding a function to the RealPage sync to check for units that are no longer in the API and delete those

## 0.4.2

- Updating Yardi sync to pull in unit-level amenities

## 0.4.1

- Updating RealPage sync to pull unit information.
- Updating RealPage sync to bubble unit pricing data up to the floorplan level.
- Starting on code standard review.

## 0.4.0

- Adding update capability for the plugin (for Rent Fetch Sync).

## 0.3.5

- Updating prefixes and page names

## 0.3.4

- Fixed an issue where properties, units, and floorplans weren't being properly deleted when the property ID no longer appeared in the settings

## 0.3.3

- Bugfix: The Realpage API returns an array of arrays for units when there are multiple for properties, but inconsistently it returns a single array (not nested) there's just one. This was causing our logic to fail when saving availability data. Added code to detect this and convert it into an array of arrays.

## 0.3.2

- Bugfix: Floorplans not saving their available units when being synced from RealPage
- Bugfix: Floorplans not saving their available units as 0 when there are no units in the unitslist API (null value breaks some searches)

## 0.3

- Adding Surecart functionality

## 0.2

- Adding base functionality for RealPage (floorplans sync, units sync pulls in units but is not yet updating meta information)

## 0.1.3

- Removing a few unused fields from syncing

## 0.1.2

- Fixing a bug where we were reading the old names for some of the options (and therefore it wasn't working)

## 0.1.1

- Adding the tables check, since we need that in this plugin

## 0.1

- Initial version
