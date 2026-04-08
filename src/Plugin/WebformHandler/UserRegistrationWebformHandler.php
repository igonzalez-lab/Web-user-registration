<?php

declare(strict_types=1);

namespace Drupal\webform_user_registration\Plugin\WebformHandler;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates or updates a user account based on webform submission values.
 *
 * @WebformHandler(
 * id = "user_registration",
 * label = @Translation("User Registration"),
 * category = @Translation("User"),
 * description = @Translation("Creates or updates a user account based on submission values."),
 * cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 * results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * tokens = TRUE,
 * )
 */
final class UserRegistrationWebformHandler extends WebformHandlerBase {

  /**
   * The user account being created or updated.
   *
   * @var \Drupal\user\UserInterface|null
   */
  protected ?UserInterface $userAccount = NULL;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The password generator.
   *
   * @var \Drupal\Core\Password\PasswordGeneratorInterface
   */
  protected PasswordGeneratorInterface $passwordGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->languageManager = $container->get('language_manager');
    $instance->currentUser = $container->get('current_user');
    $instance->passwordGenerator = $container->get('password_generator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'create_user' => [
        'enabled' => FALSE,
        'roles' => [],
        'admin_approval' => TRUE,
        'admin_approval_message' => 'Thank you for applying for an account. Your account is currently pending approval by the site administrator.<br />In the meantime, a welcome message with further instructions has been sent to your email address.',
        'email_verification' => TRUE,
        'email_verification_message' => 'A welcome message with further instructions has been sent to your email address.',
        'success_message' => 'Registration successful. You are now logged in.',
      ],
      'update_user' => [
        'enabled' => FALSE,
      ],
      'user_field_mapping' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    // Get webform settings.
    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $this->getWebform();
    $webform_settings = $webform->getSettings();

    // Retrieve all mapping options:
    // Source  -> destination
    // webform -> user field.
    $mapping_options = $this->getMappingOptions();

    $form['user_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('User registration settings'),
    ];

    // User creation.
    $form['user_settings']['create_user_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enables creation of users'),
      '#description' => $this->t("If checked, this allows users to register on the site. Users' email addresses and usernames must be unique!"),
      '#return_value' => TRUE,
      '#parents' => ['settings', 'create_user', 'enabled'],
      '#default_value' => $this->configuration['create_user']['enabled'],
    ];

    // User update.
    $form['user_settings']['update_user_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enables updating of users'),
      '#description' => $this->t('If checked, an existing user will have its data updated based on the submitted webform values.'),
      '#return_value' => TRUE,
      '#parents' => ['settings', 'update_user', 'enabled'],
      '#default_value' => $this->configuration['update_user']['enabled'],
    ];

    // User creation settings.
    $form['create_user'] = [
      '#type' => 'details',
      '#title' => $this->t('User creation'),
      '#states' => [
        'visible' => [
          ':input[name="settings[create_user][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Display a warning message if AJAX is enabled.
    $webform_config = Url::fromRoute('entity.webform.settings', [
      'webform' => $webform->id(),
    ])->toString();
    $webform_confirmation_config = Url::fromRoute('entity.webform.settings_confirmation', [
      'webform' => $webform->id(),
    ])->toString();
    $message = $this->t('User registration/login currently does not support AJAX webform submissions. Please <a href=":href_webform_settings">disable AJAX</a>, or <a href=":href_confirmation_settings">set a redirect</a> upon confirmation.', [
      ':href_webform_settings' => $webform_config,
      ':href_confirmation_settings' => $webform_confirmation_config,
    ]);

    $form['create_user']['warning'] = [
      '#type' => 'webform_message',
      '#message_type' => 'warning',
      '#message_message' => $message,
      '#visible' => $webform_settings['ajax'],
    ];

    // Default roles upon registration (Actualizado para Drupal 10+).
    $roles_entities = \Drupal\user\Entity\Role::loadMultiple();
    unset($roles_entities['anonymous']);
    $roles = [];
    foreach ($roles_entities as $id => $role) {
      $roles[$id] = \Drupal\Component\Utility\Html::escape($role->label());
    }

    $form['create_user']['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Roles'),
      '#description' => $this->t('Assigns the newly created user with the following roles.'),
      '#options' => $roles,
      '#default_value' => $this->configuration['create_user']['roles'],
      '#parents' => ['settings', 'create_user', 'roles'],
      '#access' => $roles && $this->currentUser->hasPermission('administer permissions'),
    ];

    // Special handling for the inevitable "Authenticated user" role.
    $form['create_user']['roles'][RoleInterface::AUTHENTICATED_ID] = [
      '#default_value' => RoleInterface::AUTHENTICATED_ID,
      '#disabled' => TRUE,
    ];

    $form['create_user']['admin_approval'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registration requires admin approval.'),
      '#description' => $this->t("If checked, visitors can create accounts, but they don't become active without administrative approval."),
      '#return_value' => TRUE,
      '#parents' => ['settings', 'create_user', 'admin_approval'],
      '#default_value' => $this->configuration['create_user']['admin_approval'],
    ];

    $form['create_user']['admin_approval_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message to show to visitors.'),
      '#title_display' => 'invisible',
      '#description' => $this->t('A message displayed after the form is submitted.'),
      '#rows' => 2,
      '#placeholder' => $this->configuration['create_user']['admin_approval_message'],
      '#states' => [
        'visible' => [
          ':input[name="settings[create_user][admin_approval]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['create_user']['email_verification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require email verification when a visitor creates an account.'),
      '#description' => $this->t("New users will be required to validate their email address prior to logging into the site, and will be assigned a system-generated password. With this setting disabled, users will be logged in immediately upon registering."),
      '#return_value' => TRUE,
      '#parents' => ['settings', 'create_user', 'email_verification'],
      '#default_value' => $this->configuration['create_user']['email_verification'],
    ];

    $form['create_user']['email_verification_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message to show to visitors.'),
      '#title_display' => 'invisible',
      '#description' => $this->t('A message displayed after the form is submitted.'),
      '#rows' => 2,
      '#placeholder' => $this->configuration['create_user']['email_verification_message'],
      '#states' => [
        'visible' => [
          ':input[name="settings[create_user][email_verification]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['create_user']['success_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Registration success message.'),
      '#title_display' => 'invisible',
      '#description' => $this->t('A message displayed to visitors upon successful registration/login.'),
      '#rows' => 2,
      '#placeholder' => $this->configuration['create_user']['success_message'],
    ];

    // User field mapping.
    $form['mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('User field mapping'),
    ];

    $form['mapping']['user_field_mapping'] = [
      '#type' => 'webform_mapping',
      '#description' => $this->t('Map webform element values to user fields.'),
      '#required' => FALSE,
      '#source' => $mapping_options['source'],
      '#destination' => $mapping_options['destination'],
      '#default_value' => $this->configuration['user_field_mapping'],
      '#source__title' => $this->t('Webform element'),
      '#destination__type' => 'select',
      '#destination__title' => $this->t('User field destination'),
      '#destination__description' => NULL,
      '#parents' => ['settings', 'user_field_mapping'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);

    // Get the mapping between webform elements and user entity properties or
    // fields.
    $user_field_mapping = $form_state->getValue('user_field_mapping', []);

    // Ensure we have a valid mapping for email and username if we are creating
    // new users.
    $create_user_enabled = $form_state->getValue(['create_user', 'enabled'], FALSE);
    if ($create_user_enabled) {
      // User Account creation requires at least a unique email address.
      // Assert we have a webform element as the source for a user email
      // address.
      if (!in_array('mail', $user_field_mapping)) {
        $form_state->setErrorByName('user_field_mapping', $this->t('User creation requires at least a source for email address'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission): void {
    parent::validateForm($form, $form_state, $webform_submission);

    /** @var \Drupal\user\UserInterface $account */
    $account = NULL;
    // Get the user data from the webform.
    $user_data = $this->getWebformUserData($webform_submission);

    // Skip further validation if no user data is present.
    if (empty($user_data)) {
      return;
    }

    if ($this->currentUser->isAnonymous()) {
      if ($this->configuration['create_user']['enabled']) {
        $account = $this->createUserAccount($user_data);
      }
    }
    else {
      if ($this->configuration['update_user']['enabled']) {
        // Update user account with submitted values.
        $account = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
        $this->updateUserAccount($account, $user_data);
      }
    }

    // If no account is created or updated we do not want to proceed with
    // validation.
    if (empty($account)) {
      return;
    }

    // Flag violations of user fields and properties.
    /** @var \Drupal\Core\Entity\EntityConstraintViolationListInterface $violations */
    $violations = $account->validate();
    // Display any user entity validation messages on the webform.
    if (count($violations) > 0) {
      // Load the mapping between webform elements and the user entity fields.
      $user_field_mapping = $this->configuration['user_field_mapping'];
      foreach ($violations as $violation) {
        [$user_field_name] = explode('.', $violation->getPropertyPath(), 2);
        $webform_element_name = array_search($user_field_name, $user_field_mapping);
        $form_state->setErrorByName($webform_element_name, $violation->getMessage());
      }
    }

    // Store the user account for further handling.
    // See postSave();
    $this->userAccount = $account;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE): void {
    $account = $this->userAccount;

    if ($account === NULL) {
      return;
    }

    $result = $account->save();

    // If this is a newly created user account.
    if ($result === SAVED_NEW) {
      $message = '';
      $admin_approval = $this->configuration['create_user']['admin_approval'];
      $email_verification = $this->configuration['create_user']['email_verification'];

      // Does the registration require admin approval?
      if ($admin_approval) {
        $message = $this->configuration['create_user']['admin_approval_message'];
        _user_mail_notify('register_pending_approval', $account);
        // As it's a new account and the user will not be automatically logged
        // in - as admin approval is required - set the submission owner.
        $webform_submission->setOwner($account);
        $webform_submission->save();
      }
      // Do we need to send an email verification to the user?
      elseif ($email_verification) {
        $message = $this->configuration['create_user']['email_verification_message'];
        _user_mail_notify('register_no_approval_required', $account);
        // As it's a new account and the user will not be automatically logged
        // in - as email verification is required - set the submission owner.
        $webform_submission->setOwner($account);
        $webform_submission->save();
      }
      else {
        $message = $this->configuration['create_user']['success_message'];
        // @todo The below call is problematic when using AJAX to handle
        // webform submissions. Drupal suspects the form is being submitted
        // in a suspicious way. See setInvalidTokenError() which is being
        // called in FormBuilder->doBuildForm().
        // Log the user in immediately.
        user_login_finalize($account);
      }

      if (!empty($message)) {
        // Messages are stored in configuration and are already translatable
        // via config translation. Do not pass মজthrough t().
        $this->messenger()->addStatus($message);
      }
    }
  }

  /**
   * Creates a new user account based on a list of values.
   *
   * This does NOT save the user entity. This happens in the postSave()
   * function.
   *
   * @param array $user_data
   * Associative array of user data, keyed by user entity property/field.
   *
   * @return \Drupal\user\UserInterface
   * The user account entity, populated with values.
   */
  private function createUserAccount(array $user_data): UserInterface {
    $lang = $this->languageManager->getCurrentLanguage()->getId();
    $mail = $user_data['mail'];
    $default_user_data = [
      'init' => $mail,
      'name' => str_replace('@', '.', $mail),
      'pass' => $this->passwordGenerator->generate(),
      'langcode' => $lang,
      'preferred_langcode' => $lang,
      'preferred_admin_langcode' => $lang,
      'roles' => array_keys(array_filter($this->configuration['create_user']['roles'])),
    ];
    $user_data = array_merge($default_user_data, $user_data);

    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->create([]);
    $account->enforceIsNew();

    foreach ($user_data as $name => $value) {
      $account->set($name, $value);
    }

    // Does the account require admin approval?
    $admin_approval = $this->configuration['create_user']['admin_approval'];
    if ($admin_approval) {
      // The account registration requires further approval.
      $account->block();
    }
    else {
      // No further admin approval is required, log the user in.
      $account->activate();
    }

    return $account;
  }

  /**
   * Updates a given user account based on a list of values.
   *
   * This does NOT save the user entity!
   *
   * @param \Drupal\user\UserInterface $account
   * The user account to set the values on.
   * @param array $user_data
   * Associative array of user data, keyed by user entity property/field.
   */
  private function updateUserAccount(UserInterface $account, array $user_data): void {
    // User entity does not allow us to update the email address if the
    // password is not present.
    if (isset($user_data['mail']) && !isset($user_data['pass'])) {
      unset($user_data['mail']);
    }

    foreach ($user_data as $name => $value) {
      $account->set($name, $value);
    }
  }

  /**
   * Extracts all user values from submission data.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $webform_submission
   * The webform submission.
   *
   * @return array
   * Associative array of user values, keyed by user entity property/field.
   */
  private function getWebformUserData(WebformSubmissionInterface $webform_submission): array {
    $webform_data = $webform_submission->getData();
    $user_field_mapping = $this->configuration['user_field_mapping'];

    $user_field_data = [];
    foreach ($user_field_mapping as $webform_key => $user_field) {
      // Grab the value from the webform element and assign it to the correct
      // user field key. Use null coalescing to handle missing keys gracefully.
      $user_field_data[$user_field] = $webform_data[$webform_key] ?? NULL;
    }

    return $user_field_data;
  }

  /**
   * Returns an array of source and destination options for field mapping.
   *
   * Source options contain possible webform elements.
   * Destination options contain user entity properties and fields.
   *
   * @return array
   * Array with 'source' and 'destination' keys containing mapping options.
   */
  private function getMappingOptions(): array {
    $source_options = [];
    $destination_options = [];

    // Load all webform elements.
    /** @var \Drupal\webform\Plugin\WebformElementInterface[] $webform_elements */
    $webform_elements = $this->webform->getElementsInitializedFlattenedAndHasValue();
    foreach ($webform_elements as $key => $element) {
      $source_options[$key] = $element['#admin_title'] ?? $element['#title'] ?? $key;
    }

    // Load all user entity fields.
    $user_fields = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    foreach ($user_fields as $key => $field) {
      $destination_options[$key] = (string) $field->getLabel();
    }

    return [
      'source' => $source_options,
      'destination' => $destination_options,
    ];
  }

}