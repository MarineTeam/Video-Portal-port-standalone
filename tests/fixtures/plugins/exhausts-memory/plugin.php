<?php
/**
 * Plugin Name: Exhausts memory
 * Slug: exhausts-memory
 * Version: 1.0.0
 */
$hog = [];
while (true) {
    $hog[] = str_repeat('x', 1024 * 1024);
}
