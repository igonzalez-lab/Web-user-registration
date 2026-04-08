<?php

declare(strict_types=1);

namespace Drupal\webform_user_registration\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for the Webform User Registration module.
 */
final class WebformUserRegistrationHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $routeName, RouteMatchInterface $routeMatch): string {
    if ($routeName === 'help.page.webform_user_registration') {
      $output = '';
      $output .= '<h3>' . $this->t('About') . '</h3>';
      $output .= '<p>' . $this->t('Create or update a user account upon webform submission.') . '</p>';
      return $output;
    }
    return '';
  }

}
