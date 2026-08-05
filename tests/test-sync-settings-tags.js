'use strict';

var assert = require('node:assert/strict');
var parseIds = require('../assets/js/rentfetch-sync-settings-tags.js').parseIds;

assert.deepEqual(parseIds('one, two\nthree one'), ['one', 'two', 'three']);
assert.deepEqual(parseIds('  identifier  '), ['identifier']);
assert.deepEqual(parseIds(''), []);

console.log('Sync settings tag tests passed.');
