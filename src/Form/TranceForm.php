<?php

namespace Drupal\trance\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Form controller for trance edit forms.
 *
 * @ingroup trance
 */
class TranceForm extends ContentEntityForm {

  /**
   * The entity type.
   *
   * @var \string
   */
  public static $entityType = 'trance';

  /**
   * The bundle entity type.
   *
   * @var \string
   */
  public static $bundleEntityType = 'trance_type';

  /**
   * The entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityStorage;

  /**
   * The entity type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityTypeStorage;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The datetime service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $dateTime;

  /**
   * The content entity.
   *
   * @var \Drupal\trance\TranceInterface
   */
  protected $entity;

  /**
   * Constructs a TranceForm object.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityStorageInterface $trance_storage
   *   The trance storage.
   * @param \Drupal\Core\Entity\EntityStorageInterface $trance_type_storage
   *   The trance type storage.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Component\Datetime\TimeInterface $datetime
   *   The datetime service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityStorageInterface $trance_storage, EntityStorageInterface $trance_type_storage, LanguageManagerInterface $language_manager, TimeInterface $datetime) {
    parent::__construct($entity_repository);
    $this->entityStorage = $trance_storage;
    $this->entityTypeStorage = $trance_type_storage;
    $this->languageManager = $language_manager;
    $this->dateTime = $datetime;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $entity_type = '', $entity_bundle_type = '') {
    $entity_type_manager = $container->get('entity_type.manager');
    return new static(
      $container->get('entity.repository'),
      $entity_type_manager->getStorage($entity_type ? $entity_type : self::$entityType),
      $entity_type_manager->getStorage($entity_bundle_type ? $entity_bundle_type : self::$bundleEntityType),
      $container->get('language_manager'),
      $container->get('datetime.time')
    );
  }

  /**
   * Overrides \Drupal\Core\Entity\EntityForm::prepareEntity().
   *
   * Prepares the entity. Fills in a few default values.
   */
  protected function prepareEntity() {
    $entity = $this->entity;
    // Set up default values, if required.
    $entity_bundle = $this->entityTypeStorage->load($entity->bundle());
    if (!$entity->isNew()) {
      $entity->setRevisionLog(NULL);
    }
    // Always use the default revision setting.
    $entity->setNewRevision($entity_bundle->shouldCreateNewRevision());
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    if (empty($form['advanced'])) {
      $form['advanced'] = [
        '#type' => 'vertical_tabs',
        '#weight' => 99,
      ];
    }

    // Add a log field if the "Create new revision" option is checked, or if the
    // current user has the ability to check that option.
    $form['revision_information'] = [
      '#type' => 'details',
      '#title' => $this->t('Revision information'),
      // Open by default when "Create new revision" is checked.
      '#open' => $this->entity->isNewRevision(),
      '#group' => 'advanced',
      '#weight' => 20,
    ];

    $form['revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create new revision'),
      '#default_value' => $this->entity->isNewRevision(),
      '#group' => 'revision_information',
    ];

    // Check the revision log checkbox when the log textarea is filled in.
    // This must not happen if "Create new revision" is enabled by default,
    // since the state would auto-disable the checkbox otherwise.
    if (!$this->entity->isNewRevision()) {
      $form['revision']['#states'] = [
        'checked' => [
          'textarea[name="revision_log"]' => ['empty' => FALSE],
        ],
      ];
    }

    $form['revision_log'] += array(
      '#states' => array(
        'visible' => array(
          ':input[name="revision"]' => array('checked' => TRUE),
        ),
      ),
      '#group' => 'revision_information',
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->entity->setRevisionCreationTime($this->dateTime->getRequestTime());
    $this->entity->setRevisionAuthorId(\Drupal::currentUser()->id());
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;
    $entity_type = $entity->getEntityType()->id();

    // Save as a new revision if requested to do so.
    if (!$form_state->isValueEmpty('revision')) {
      $entity->setNewRevision();
    }
    $status = parent::save($form, $form_state);

    switch ($status) {
      case SAVED_NEW:
        $this->messenger()->addMessage(
          $this->t(
            'Created the %label content entity.', [
            '%label' => $entity->label(),
          ])
        );
        break;

      default:
        $this->messenger()->addMessage(
          $this->t(
            'Saved the %label content entity.',
            ['%label' => $entity->label(),])
        );
    }
    $form_state->setRedirect('entity.' . $entity_type . '.edit_form', [
      $entity_type => $entity->id(),
    ]);
  }

}
