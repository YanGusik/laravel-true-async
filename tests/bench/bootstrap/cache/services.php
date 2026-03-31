<?php return array (
  'providers' => 
  array (
    0 => 'Illuminate\\View\\ViewServiceProvider',
    1 => 'Illuminate\\Filesystem\\FilesystemServiceProvider',
    2 => 'Illuminate\\Log\\LogServiceProvider',
    3 => 'Illuminate\\Translation\\TranslationServiceProvider',
    4 => 'Illuminate\\Routing\\RoutingServiceProvider',
    5 => 'Spawn\\Laravel\\AsyncServiceProvider',
  ),
  'eager' => 
  array (
    0 => 'Illuminate\\View\\ViewServiceProvider',
    1 => 'Illuminate\\Filesystem\\FilesystemServiceProvider',
    2 => 'Illuminate\\Log\\LogServiceProvider',
    3 => 'Illuminate\\Routing\\RoutingServiceProvider',
    4 => 'Spawn\\Laravel\\AsyncServiceProvider',
  ),
  'deferred' => 
  array (
    'translator' => 'Illuminate\\Translation\\TranslationServiceProvider',
    'translation.loader' => 'Illuminate\\Translation\\TranslationServiceProvider',
  ),
  'when' => 
  array (
    'Illuminate\\Translation\\TranslationServiceProvider' => 
    array (
    ),
  ),
);