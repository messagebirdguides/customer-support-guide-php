<?php

// Create/open the database file
$db = new PDO('sqlite:support.sqlite');

// Generate schema: 2 tables
$db->exec('CREATE TABLE tickets (id INTEGER PRIMARY KEY, number TEXT, open INTEGER)');
$db->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, ticket_id INTEGER, direction TEXT, content TEXT, FOREIGN KEY (ticket_id) REFERENCES tickets(id))');