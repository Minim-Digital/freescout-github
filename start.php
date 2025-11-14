<?php

/**
 * GitHub Integration Module for FreeScout
 * 
 * This module integrates FreeScout with GitHub to enable support teams 
 * to create, link, and track GitHub issues directly from support conversations.
 */

// Load module routes when running as a standalone FreeScout module
if (class_exists('\Route')) {
    require __DIR__ . '/Http/routes.php';
}