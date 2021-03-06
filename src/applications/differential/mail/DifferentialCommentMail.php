<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class DifferentialCommentMail extends DifferentialMail {

  protected $changedByCommit;

  private $addedReviewers;
  private $addedCCs;

  public function setChangedByCommit($changed_by_commit) {
    $this->changedByCommit = $changed_by_commit;
    return $this;
  }

  public function getChangedByCommit() {
    return $this->changedByCommit;
  }

  public function __construct(
    DifferentialRevision $revision,
    PhabricatorObjectHandle $actor,
    DifferentialComment $comment,
    array $changesets,
    array $inline_comments) {
    assert_instances_of($changesets, 'DifferentialChangeset');
    assert_instances_of($inline_comments, 'PhabricatorInlineCommentInterface');

    $this->setRevision($revision);
    $this->setActorHandle($actor);
    $this->setComment($comment);
    $this->setChangesets($changesets);
    $this->setInlineComments($inline_comments);

  }

  protected function getMailTags() {
    $comment = $this->getComment();
    $action = $comment->getAction();

    $tags = array();
    switch ($action) {
      case DifferentialAction::ACTION_ADDCCS:
        $tags[] = MetaMTANotificationType::TYPE_DIFFERENTIAL_CC;
        break;
      case DifferentialAction::ACTION_CLOSE:
        $tags[] = MetaMTANotificationType::TYPE_DIFFERENTIAL_CLOSED;
        break;
    }

    if (strlen(trim($comment->getContent()))) {
      switch ($action) {
        case DifferentialAction::ACTION_CLOSE:
          // Commit comments are auto-generated and not especially interesting,
          // so don't tag them as having a comment.
          break;
        default:
          $tags[] = MetaMTANotificationType::TYPE_DIFFERENTIAL_COMMENT;
          break;
      }
    }

    return $tags;
  }

  protected function renderVaryPrefix() {
    $verb = ucwords($this->getVerb());
    return "[{$verb}]";
  }

  protected function getVerb() {
    $comment = $this->getComment();
    $action = $comment->getAction();
    $verb = DifferentialAction::getActionPastTenseVerb($action);
    return $verb;
  }

  protected function prepareBody() {
    parent::prepareBody();

    // If the commented added reviewers or CCs, list them explicitly.
    $meta = $this->getComment()->getMetadata();
    $m_reviewers = idx(
      $meta,
      DifferentialComment::METADATA_ADDED_REVIEWERS,
      array());
    $m_cc = idx(
      $meta,
      DifferentialComment::METADATA_ADDED_CCS,
      array());
    $load = array_merge($m_reviewers, $m_cc);
    if ($load) {
      $handles = id(new PhabricatorObjectHandleData($load))->loadHandles();
      if ($m_reviewers) {
        $this->addedReviewers = $this->renderHandleList($handles, $m_reviewers);
      }
      if ($m_cc) {
        $this->addedCCs = $this->renderHandleList($handles, $m_cc);
      }
    }
  }

  protected function renderBody() {

    $comment = $this->getComment();

    $actor = $this->getActorName();
    $name  = $this->getRevision()->getTitle();
    $verb  = $this->getVerb();

    $body  = array();

    $body[] = "{$actor} has {$verb} the revision \"{$name}\".";

    if ($this->addedReviewers) {
      $body[] = 'Added Reviewers: '.$this->addedReviewers;
    }
    if ($this->addedCCs) {
      $body[] = 'Added CCs: '.$this->addedCCs;
    }

    $body[] = null;

    $content = $comment->getContent();
    if (strlen($content)) {
      $body[] = $this->formatText($content);
      $body[] = null;
    }

    if ($this->getChangedByCommit()) {
      $body[] = 'CHANGED PRIOR TO COMMIT';
      $body[] = '  '.$this->getChangedByCommit();
      $body[] = null;
    }

    $inlines = $this->getInlineComments();
    if ($inlines) {
      $body[] = 'INLINE COMMENTS';
      $changesets = $this->getChangesets();

      if (PhabricatorEnv::getEnvConfig(
            'metamta.differential.unified-comment-context', false)) {
        foreach ($changesets as $changeset) {
          $changeset->attachHunks($changeset->loadHunks());
        }
      }
      foreach ($inlines as $inline) {
        $changeset = $changesets[$inline->getChangesetID()];
        if (!$changeset) {
          throw new Exception('Changeset missing!');
        }
        $file = $changeset->getFilename();
        $start = $inline->getLineNumber();
        $len = $inline->getLineLength();
        if ($len) {
          $range = $start.'-'.($start + $len);
        } else {
          $range = $start;
        }

        $inline_content = $inline->getContent();

        if (!PhabricatorEnv::getEnvConfig(
              'metamta.differential.unified-comment-context', false)) {
          $body[] = $this->formatText("{$file}:{$range} {$inline_content}");
        } else {
          $body[] = "================";
          $body[] = "Comment at: " . $file . ":" . $range;
          $body[] = $changeset->makeContextDiff($inline, 1);
          $body[] = "----------------";

          $body[] = $inline_content;
          $body[] = null;
        }
      }
      $body[] = null;
    }

    $body[] = $this->renderAuxFields(DifferentialMailPhase::COMMENT);

    return implode("\n", $body);
  }
}
