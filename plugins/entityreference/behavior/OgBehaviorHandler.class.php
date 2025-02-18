<?php

/**
 * OG behavior handler.
 */
class OgBehaviorHandler extends EntityReferenceBehaviorHandler {

  /**
   * Implements EntityReferenceBehaviorHandler::access().
   */
  public function access($field, $instance) {
    return $field['settings']['handler'] == 'og' || strpos($field['settings']['handler'], 'og_') === 0;
  }

  /**
   * Implements EntityReferenceBehaviorHandler::load().
   */
  public function load($entity_type, $entities, $field, $instances, $langcode, &$items) {
    // Get the OG memberships from the field.
    $field_name = $field['field_name'];
    $target_type = $field['settings']['target_type'];
    foreach ($entities as $entity) {
      $wrapper = entity_metadata_wrapper($entity_type, $entity);
      if (empty($wrapper->{$field_name})) {
        // If the entity belongs to a bundle that was deleted, return early.
        continue;
      }
      $id = $wrapper->getIdentifier();
      $items[$id] = array();
      $gids = og_get_entity_groups($entity_type, $entity, array(), $field_name);

      if (empty($gids[$target_type])) {
        continue;
      }

      foreach ($gids[$target_type] as $gid) {
        $items[$id][] = array(
          'target_id' => $gid,
        );
      }
    }
  }

  /**
   * Implements EntityReferenceBehaviorHandler::insert().
   */
  public function insert($entity_type, $entity, $field, $instance, $langcode, &$items) {
    if (!empty($entity->skip_og_membership)) {
      return;
    }
    $this->OgMembershipCrud($entity_type, $entity, $field, $instance, $langcode, $items);
    $items = array();
  }

  /**
   * Implements EntityReferenceBehaviorHandler::access().
   */
  public function update($entity_type, $entity, $field, $instance, $langcode, &$items) {
    if (!empty($entity->skip_og_membership)) {
      return;
    }
    $this->OgMembershipCrud($entity_type, $entity, $field, $instance, $langcode, $items);
    $items = array();
  }

  /**
   * Implements EntityReferenceBehaviorHandler::Delete()
   *
   * CRUD memberships from field, or if entity is marked for deleteing,
   * delete all the OG membership related to it.
   *
   * @see og_entity_delete().
   */
  public function delete($entity_type, $entity, $field, $instance, $langcode, &$items) {
    if (!empty($entity->skip_og_membership)) {
      return;
    }
    if (!empty($entity->delete_og_membership)) {
      // Delete all OG memberships related to this entity.
      $og_memberships = array();
      foreach (og_get_entity_groups($entity_type, $entity) as $group_type => $ids) {
        $og_memberships = array_merge($og_memberships, array_keys($ids));
      }
      if ($og_memberships) {
        og_membership_delete_multiple($og_memberships);
      }

    }
    else {
      $this->OgMembershipCrud($entity_type, $entity, $field, $instance, $langcode, $items);
    }
  }

  /**
   * Create, update or delete OG membership based on field values.
   */
  public function OgMembershipCrud($entity_type, $entity, $field, $instance, $langcode, &$items) {
    if (!user_access('administer group') && !field_access('edit', $field, $entity_type, $entity)) {
      // User has no access to field.
      return;
    }
    if (!$diff = $this->groupAudiencegetDiff($entity_type, $entity, $field, $instance, $langcode, $items)) {
      return;
    }

    $field_name = $field['field_name'];
    $group_type = $field['settings']['target_type'];

    $diff += array('insert' => array(), 'delete' => array());

    // Delete first, so we don't trigger cardinality errors.
    if ($diff['delete']) {
      og_membership_delete_multiple($diff['delete']);
    }

    if (!$diff['insert']) {
      return;
    }

    // Prepare an array with the membership state, if it was provided in the widget.
    $states = array();
    foreach ($items as $item) {
      $gid = $item['target_id'];

      if (empty($item['state']) || !in_array($gid, $diff['insert'])) {
        // State isn't provided, or not an "insert" operation.
        continue;
      }

      $states[$gid] = $item['state'];
    }

    foreach ($diff['insert'] as $gid) {
      $values = array(
        'entity_type' => $entity_type,
        'entity' => $entity,
        'field_name' => $field_name,
      );

      if (!empty($states[$gid])) {
        $values['state'] = $states[$gid];
      }

      og_group($group_type, $gid, $values);
    }
  }

  /**
   * Get the difference in group audience for a saved field.
   *
   * @return
   *   Array with all the differences, or an empty array if none found.
   */
  public function groupAudiencegetDiff($entity_type, $entity, $field, $instance, $langcode, $items) {
    $return = FALSE;

    $field_name = $field['field_name'];
    $wrapper = entity_metadata_wrapper($entity_type, $entity);
    $og_memberships = $wrapper->{$field_name . '__og_membership'}->value();

    $new_memberships = array();
    foreach ($items as $item) {
      $new_memberships[$item['target_id']] = TRUE;
    }

    foreach ($og_memberships as $og_membership) {
      $gid = $og_membership->gid;
      if (empty($new_memberships[$gid])) {
        // Membership was deleted.
        if ($og_membership->entity_type == 'user') {
          // Make sure this is not the group manager, if exists.
          $group = entity_load($og_membership->group_type, $og_membership->gid);
          if (!empty($group->uid) && $group->uid == $og_membership->etid) {
            continue;
          }
        }

        $return['delete'][] = $og_membership->id;
        unset($new_memberships[$gid]);
      }
      else {
        // Existing membership.
        unset($new_memberships[$gid]);
      }
    }
    if ($new_memberships) {
      // New memberships.
      $return['insert'] = array_keys($new_memberships);
    }

    return $return;
  }

  /**
   * Implements EntityReferenceBehaviorHandler::views_data_alter().
   */
  public function views_data_alter(&$data, $field) {
    // We need to override the default EntityReference table settings when OG
    // behavior is being used.
    if (og_is_group_audience_field($field['field_name'])) {
      $entity_types = array_keys($field['bundles']);
      // We need to join the base table for the entities
      // that this field is attached to.
      foreach ($entity_types as $entity_type) {
        $entity_info = entity_get_info($entity_type);
        $data['og_membership']['table']['join'][$entity_info['base table']] = array(
          // Join entity base table on its id field with left_field.
          'left_field' => $entity_info['entity keys']['id'],
          'field' => 'etid',
          'extra' => array(
            0 => array(
              'field' => 'entity_type',
              'value' => $entity_type,
            ),
          ),
        );
          // Copy the original config from the table definition.
        $data['og_membership'][$field['field_name']] = $data['field_data_' . $field['field_name']][$field['field_name']];
        $data['og_membership'][$field['field_name'] . '_target_id'] = $data['field_data_' . $field['field_name']][$field['field_name'] . '_target_id'];

        // Change config with settings from og_membership table.
        foreach (array('filter', 'argument', 'sort', 'relationship') as $op) {
          $data['og_membership'][$field['field_name'] . '_target_id'][$op]['field'] = 'gid';
          $data['og_membership'][$field['field_name'] . '_target_id'][$op]['table'] = 'og_membership';
          unset($data['og_membership'][$field['field_name'] . '_target_id'][$op]['additional fields']);
        }

        // Add gid as the relationship field.
        $data['og_membership'][$field['field_name'] . '_target_id']['relationship']['field'] = 'gid';
      }

      // Get rid of the original table configs.
      unset($data['field_data_' . $field['field_name']]);
      unset($data['field_revision_' . $field['field_name']]);
    }
  }

  /**
   * Implements EntityReferenceBehaviorHandler::validate().
   *
   * Re-build $errors array to be keyed correctly by "default" and "admin" field
   * modes.
   *
   * @todo: Try to get the correct delta so we can highlight the invalid
   * reference.
   *
   * @see entityreference_field_validate().
   */
  public function validate($entity_type, $entity, $field, $instance, $langcode, $items, &$errors) {
    $new_errors = array();
    $values = array('default' => array(), 'admin' => array());

    $item = reset($items);
    if (!empty($item['field_mode'])) {
      // This is a complex widget with "default" and "admin" field modes.
      foreach ($items as $item) {
        $values[$item['field_mode']][] = $item['target_id'];
      }
    }
    else {
      foreach ($items as $item) {
        if (!entityreference_field_is_empty($item, $field) && $item['target_id'] !== NULL) {
          $values['default'][] = $item['target_id'];
        }
      }
    }

    $field_name = $field['field_name'];

    foreach ($values as $field_mode => $ids) {
      if (!$ids) {
        continue;
      }

      if ($field_mode == 'admin' && !user_access('administer group')) {
        // No need to validate the admin, as the user has no access to it.
        continue;
      }

      $instance['field_mode'] = $field_mode;
      $valid_ids = entityreference_get_selection_handler($field, $instance, $entity_type, $entity)->validateReferencableEntities($ids);

      if ($invalid_entities = array_diff($ids, $valid_ids)) {
        foreach ($invalid_entities as $id) {
          $new_errors[$field_mode][] = array(
            'error' => 'og_invalid_entity',
            'message' => t('The referenced group (@type: @id) is invalid.', array('@type' => $field['settings']['target_type'], '@id' => $id)),
          );
        }
      }
    }

    if ($new_errors) {
      og_field_widget_register_errors($field_name, $new_errors);
      // We throw an exception ourself, as we unset the $errors array.
      throw new FieldValidationException($new_errors);
    }

    // Errors for this field now handled, removing from the referenced array.
    unset($errors[$field_name]);
  }
}
