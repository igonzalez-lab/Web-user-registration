# Webform User Registration

## Description

This module provides a webform handler that creates or updates user accounts
upon form submission. It allows site builders to create custom registration
forms using Webform's powerful form building capabilities.

## Features

- Create new user accounts from webform submissions
- Update existing user accounts for authenticated users
- Map webform elements to user entity fields
- Configure admin approval requirements
- Configure email verification requirements
- Assign roles to newly created users
- Customizable success/status messages

## Requirements

- Drupal 10.3+ or Drupal 11
- Webform module 6.2+
- PHP 8.1+

## Installation

### With Composer (recommended)

```bash
composer require drupal/webform_user_registration
drush en webform_user_registration
```

### Manual installation

1. Download the module from Drupal.org
2. Extract to your modules directory
3. Enable via Drupal admin UI or drush

## Configuration

1. Create or edit a webform
2. Go to Settings > Emails/Handlers
3. Add the "User Registration" handler
4. Configure user creation/update settings
5. Map webform elements to user fields

## Maintainers

- Oleg Ivanov ([oivanov](https://www.drupal.org/u/oivanov))
- Berdir ([berdir](https://www.drupal.org/u/berdir))
