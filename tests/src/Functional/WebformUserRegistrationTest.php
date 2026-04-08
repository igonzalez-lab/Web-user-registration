<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_user_registration\Functional;

use Drupal\Tests\webform\Functional\WebformBrowserTestBase;

/**
 * Functional tests for the webform_user_registration webform plugin.
 *
 * @group webform_user_registration
 */
final class WebformUserRegistrationTest extends WebformBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'webform_user_registration_test',
    'webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $testWebforms = [
    'webform_user_registration_test',
  ];

  /**
   * Test user registration without admin approval/email verification.
   */
  public function testUserRegistration(): void {
    $webform = $this->loadWebform('webform_user_registration_test');

    // Allow registration by site visitors without administrator approval.
    $edit = [
      'first_name' => 'Dru',
      'last_name' => 'Paul',
      'email' => 'dru.paul@example.com',
      'phone' => '123-456-7890',
    ];
    $this->postSubmission($webform, $edit);
    $this->assertSession()->responseContains('Registration successful. You are now logged in.');
    $this->drupalLogout();
  }

  /**
   * Test user registration with email verification.
   */
  public function testUserRegistrationWithEmailVerification(): void {
    $config = $this->config('webform.webform.webform_user_registration_test');
    $webform = $this->loadWebform('webform_user_registration_test');

    // Require email verification.
    $config->set('handlers.user_registration.settings.create_user.email_verification', TRUE)->save();

    // Allow registration by site visitors without administrator approval.
    $edit = [
      'first_name' => 'Dru',
      'last_name' => 'Paul',
      'email' => 'dru.paul@example.com',
      'phone' => '123-456-7890',
    ];
    $this->postSubmission($webform, $edit);
    $this->assertSession()->responseContains('A welcome message with further instructions has been sent to your email address.');

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $account_name = str_replace('@', '.', $edit['email']);
    $accounts = $storage->loadByProperties(['name' => $account_name, 'mail' => $edit['email']]);
    $new_user = reset($accounts);

    // Assert new user is active.
    $this->assertTrue($new_user->isActive(), 'New account is active after registration.');

    // Has phone number.
    $this->assertEquals($new_user->field_phone->value, $edit['phone']);

    // And can set password.
    $resetURL = user_pass_reset_url($new_user);
    $this->drupalGet($resetURL);
    $this->assertSession()->responseContains('Set password | Drupal');
  }

  /**
   * Test user registration with admin approval.
   */
  public function testUserRegistrationWithAdminApproval(): void {
    $config = $this->config('webform.webform.webform_user_registration_test');
    $webform = $this->loadWebform('webform_user_registration_test');

    // Require admin approval.
    $config->set('handlers.user_registration.settings.create_user.admin_approval', TRUE)->save();

    // Allow registration by site visitors with administrator approval.
    $edit = [
      'first_name' => 'Dru',
      'last_name' => 'Paul',
      'email' => 'dru.paul@example.com',
      'phone' => '123-456-7890',
    ];
    $this->postSubmission($webform, $edit);
    $this->assertSession()->responseContains('Your account is pending approval.');

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $account_name = str_replace('@', '.', $edit['email']);
    $accounts = $storage->loadByProperties(['name' => $account_name, 'mail' => $edit['email']]);
    $new_user = reset($accounts);
    $this->assertFalse($new_user->isActive(), 'New account is blocked until approved by an administrator.');
  }

  /**
   * Test user account update behavior for authenticated users.
   */
  public function testUpdateUserAccount(): void {
    // Start a user session.
    $this->drupalLogin($this->createUser(['access content'], 'dru.paul'));

    $webform = $this->loadWebform('webform_user_registration_test');

    // Assert user fields have been updated.
    $edit = [
      'first_name' => 'Dru',
      'last_name' => 'Paul',
      'email' => 'dru.paul_new@example.com',
      'phone' => '206-123-4567',
    ];
    $this->postSubmission($webform, $edit);

    /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')->getStorage('user');
    $accounts = $storage->loadByProperties(['name' => 'dru.paul']);
    $user = reset($accounts);

    // Assert email was not changed.
    $this->assertNotEquals($user->getEmail(), $edit['email'], 'E-mail cannot be updated.');

    // Assert custom fields are updated.
    $this->assertEquals($user->field_phone->value, $edit['phone']);
  }

}
