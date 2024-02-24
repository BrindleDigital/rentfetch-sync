## 0.4.0

-   Adding update capability

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
