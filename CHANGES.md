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
