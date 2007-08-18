#!/usr/bin/php
<?php
// $Id$
/**
 * @file
 * Provides access checking for 'cvs tag' commands.
 *
 * Copyright 2005 by Kjartan Mannes ("Kjartan", http://drupal.org/user/2)
 * Copyright 2006, 2007 by Derek Wright ("dww", http://drupal.org/user/46549)
 * Copyright 2007 by Jakob Petsovits ("jpetso", http://drupal.org/user/56020)
 */
// TODO: move the "remove tags" restriction to Commit Restrictions
// TODO: implement the "don't remove release tags" restriction -
//       not in here, but rather in the release node integration

function xcvs_help($cli, $output_stream) {
  fwrite($output_stream, "Usage: $cli <config file> \$USER %t %b %o %p %{sTv}\n\n");
}

function xcvs_init($argc, $argv) {
  fwrite(STDERR, print_r($argv, TRUE));

  $this_file = array_shift($argv);   // argv[0]

  if ($argc < 10) {
    xcvs_help($this_file, STDERR);
    exit(2);
  }

  $config_file = array_shift($argv); // argv[1]
  $username = array_shift($argv);    // argv[2]
  $tag = array_shift($argv);         // argv[3]
  $type = array_shift($argv);        // argv[4]
  $cvs_op = array_shift($argv);      // argv[5]
  $dir = array_shift($argv);         // argv[6]

  // Load the configuration file and bootstrap Drupal.
  if (!file_exists($config_file)) {
    fwrite(STDERR, "Error: failed to load configuration file.\n");
    exit(3);
  }
  include_once $config_file;

  if (in_array($username, $xcvs['allowed_users'])) {
    // admins and other privileged users don't need to go through any checks
    exit(0);
  }

  // Do a full Drupal bootstrap.
  xcvs_bootstrap($xcvs['drupal_path']);

  switch ($cvs_op) {
    case 'add':
      $action = VERSIONCONTROL_ACTION_ADDED;
      break;

    case 'mov':
      $action = VERSIONCONTROL_ACTION_MOVED;
      break;

    case 'del':
      if (!$xcvs['allow_tag_removal']) {
        fwrite(STDERR, $xcvs['tag_delete_denied_message']);
        exit(4);
      }
      // as $type == '?', we don't know if it's branches or tags,
      // so let go without asking the Version Control API
      // TODO: I think we can work around this by logging all branches and tags
      //       for each item in the database, and afterwards looking them up.
      $action = VERSIONCONTROL_ACTION_DELETED;
      exit(0);

    default:
      fwrite(STDERR, "Error: unknown tag action.\n");
      exit(5);
  }

  // Gather info for each tagged/branched file.
  $items = array();
  while (!empty($argv)) {
    $filename = array_shift($argv);
    $source_branch = array_shift($argv);
    $old_revision = array_shift($argv);
    $new_revision = array_shift($argv);

    $item = array(
      'type' => VERSIONCONTROL_ITEM_FILE,
      'path' => '/'. $dir .'/'. $filename,
      'revision' => ($new_revision != 'NONE') ? $new_revision : $old_revision,
    );
    if ($action != VERSIONCONTROL_ACTION_DELETED) {
      $item['source branch'] = empty($source_branch) ? 'HEAD' : $source_branch;
    }

    $items[] = $item;
  }

  if (empty($items)) {
    exit(0); // if nothing is being tagged, we don't need to control access.
  }

  $tag_or_branch = array(
    'action' => $action,
    'username' => $username,
    'repo_id' => $xcvs['repo_id'],
    'cvs_specific' => array(),
  );

  if ($type == 'N') { // is a tag
    $tag_or_branch['tag_name'] = $tag;
    $access = versioncontrol_has_tag_access($tag_or_branch, $items);
  }
  else if ($type == 'T') { // is a branch
    $tag_or_branch['branch_name'] = $tag;
    $access = versioncontrol_has_branch_access($tag_or_branch, $items);
  }

  // Fail and print out error messages if branch/tag access has been denied.
  if (!$access) {
    fwrite(STDERR, implode("\n\n", versioncontrol_get_access_errors()) ."\n\n");
    exit(6);
  }
  // If we get as far as this, the tagging/branching operation may happen.

  // Remember this directory so that loginfo can combine tags/branches
  // from different directories in one tag/branch entry.
  if ($xcvs["logs_combine"]) {
    $lastlog = $tempdir .'/xcvs-lastlog.'. posix_getpgrp();
    xcvs_log_add($lastlog, $dir);
  }

  exit(0);
}

xcvs_init($argc, $argv);
