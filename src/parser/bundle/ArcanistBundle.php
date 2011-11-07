<?php

/*
 * Copyright 2011 Facebook, Inc.
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

/**
 * Converts changesets between different formats.
 *
 * @group diff
 */
class ArcanistBundle {

  private $changes;
  private $conduit;
  private $blobs = array();
  private $diskPath;

  public function setConduit(ConduitClient $conduit) {
    $this->conduit = $conduit;
  }

  public static function newFromChanges(array $changes) {
    $obj = new ArcanistBundle();
    $obj->changes = $changes;
    return $obj;
  }

  public static function newFromArcBundle($path) {
    $path = Filesystem::resolvePath($path);

    $future = new ExecFuture(
      csprintf(
        'tar xfO %s changes.json',
        $path));
    $changes = $future->resolveJSON();

    foreach ($changes as $change_key => $change) {
      foreach ($change['hunks'] as $key => $hunk) {
        list($hunk_data) = execx('tar xfO %s hunks/%s', $path, $hunk['corpus']);
        $changes[$change_key]['hunks'][$key]['corpus'] = $hunk_data;
      }
    }


    foreach ($changes as $change_key => $change) {
      $changes[$change_key] = ArcanistDiffChange::newFromDictionary($change);
    }

    $obj = new ArcanistBundle();
    $obj->changes = $changes;
    $obj->diskPath = $path;

    return $obj;
  }

  public static function newFromDiff($data) {
    $obj = new ArcanistBundle();

    $parser = new ArcanistDiffParser();
    $obj->changes = $parser->parseDiff($data);

    return $obj;
  }

  private function __construct() {

  }

  public function writeToDisk($path) {
    $changes = $this->getChanges();

    $change_list = array();
    foreach ($changes as $change) {
      $change_list[] = $change->toDictionary();
    }

    $hunks = array();
    foreach ($change_list as $change_key => $change) {
      foreach ($change['hunks'] as $key => $hunk) {
        $hunks[] = $hunk['corpus'];
        $change_list[$change_key]['hunks'][$key]['corpus'] = count($hunks) - 1;
      }
    }

    $blobs = array();
    foreach ($change_list as $change) {
      if (!empty($change['metadata']['old:binary-phid'])) {
        $blobs[$change['metadata']['old:binary-phid']] = null;
      }
      if (!empty($change['metadata']['new:binary-phid'])) {
        $blobs[$change['metadata']['new:binary-phid']] = null;
      }
    }
    foreach ($blobs as $phid => $null) {
      $blobs[$phid] = $this->getBlob($phid);
    }

    $dir = Filesystem::createTemporaryDirectory();
    Filesystem::createDirectory($dir.'/hunks');
    Filesystem::createDirectory($dir.'/blobs');
    Filesystem::writeFile($dir.'/changes.json', json_encode($change_list));
    foreach ($hunks as $key => $hunk) {
      Filesystem::writeFile($dir.'/hunks/'.$key, $hunk);
    }
    foreach ($blobs as $key => $blob) {
      Filesystem::writeFile($dir.'/blobs/'.$key, $blob);
    }
    execx(
      '(cd %s; tar -czf %s *)',
      $dir,
      Filesystem::resolvePath($path));
    Filesystem::remove($dir);
  }

  public function toUnifiedDiff() {

    $result = array();
    $changes = $this->getChanges();
    foreach ($changes as $change) {

      $old_path = $this->getOldPath($change);
      $cur_path = $this->getCurrentPath($change);

      $index_path = $cur_path;
      if ($index_path === null) {
        $index_path = $old_path;
      }

      $result[] = 'Index: '.$index_path;
      $result[] = str_repeat('=', 67);

      if ($old_path === null) {
        $old_path = '/dev/null';
      }

      if ($cur_path === null) {
        $cur_path = '/dev/null';
      }

      // When the diff is used by `patch`, `patch` ignores what is listed as the
      // current path and just makes changes to the file at the old path (unless
      // the current path is '/dev/null'.
      // If the old path and the current path aren't the same (and neither is
      // /dev/null), this indicates the file was moved or copied. By listing
      // both paths as the new file, `patch` will apply the diff to the new
      // file.
      if ($cur_path !== '/dev/null' && $old_path !== '/dev/null') {
        $old_path = $cur_path;
      }

      $result[] = '--- '.$old_path;
      $result[] = '+++ '.$cur_path;

      $result[] = $this->buildHunkChanges($change->getHunks());
    }

    return implode("\n", $result)."\n";
  }

  public function toGitPatch() {
    $result = array();
    $changes = $this->getChanges();
    foreach ($changes as $change) {
      $type = $change->getType();
      $file_type = $change->getFileType();

      if ($file_type == ArcanistDiffChangeType::FILE_DIRECTORY) {
        // TODO: We should raise a FYI about this, so the user is aware
        // that we omitted it, if the directory is empty or has permissions
        // which git can't represent.

        // Git doesn't support empty directories, so we simply ignore them. If
        // the directory is nonempty, 'git apply' will create it when processing
        // the changesets for files inside it.
        continue;
      }

      if ($type == ArcanistDiffChangeType::TYPE_MOVE_AWAY) {
        // Git will apply this in the corresponding MOVE_HERE.
        continue;
      }

      $old_mode = idx($change->getOldProperties(), 'unix:filemode', '100644');
      $new_mode = idx($change->getNewProperties(), 'unix:filemode', '100644');

      $is_binary = ($file_type == ArcanistDiffChangeType::FILE_BINARY ||
                    $file_type == ArcanistDiffChangeType::FILE_IMAGE);

      if ($is_binary) {
        $change_body = $this->buildBinaryChange($change);
      } else {
        $change_body = $this->buildHunkChanges($change->getHunks());
      }
      if ($type == ArcanistDiffChangeType::TYPE_COPY_AWAY) {
        // TODO: This is only relevant when patching old Differential diffs
        // which were created prior to arc pruning TYPE_COPY_AWAY for files
        // with no modifications.
        if (!strlen($change_body) && ($old_mode == $new_mode)) {
          continue;
        }
      }

      $old_path = $this->getOldPath($change);
      $cur_path = $this->getCurrentPath($change);

      if ($old_path === null) {
        $old_index = 'a/'.$cur_path;
        $old_target  = '/dev/null';
      } else {
        $old_index = 'a/'.$old_path;
        $old_target  = 'a/'.$old_path;
      }

      if ($cur_path === null) {
        $cur_index = 'b/'.$old_path;
        $cur_target  = '/dev/null';
      } else {
        $cur_index = 'b/'.$cur_path;
        $cur_target  = 'b/'.$cur_path;
      }

      $result[] = "diff --git {$old_index} {$cur_index}";

      if ($type == ArcanistDiffChangeType::TYPE_ADD) {
        $result[] = "new file mode {$new_mode}";
      }

      if ($type == ArcanistDiffChangeType::TYPE_COPY_HERE ||
          $type == ArcanistDiffChangeType::TYPE_MOVE_HERE ||
          $type == ArcanistDiffChangeType::TYPE_COPY_AWAY) {
        if ($old_mode !== $new_mode) {
          $result[] = "old mode {$old_mode}";
          $result[] = "new mode {$new_mode}";
        }
      }

      if ($type == ArcanistDiffChangeType::TYPE_COPY_HERE) {
        $result[] = "copy from {$old_path}";
        $result[] = "copy to {$cur_path}";
      } else if ($type == ArcanistDiffChangeType::TYPE_MOVE_HERE) {
        $result[] = "rename from {$old_path}";
        $result[] = "rename to {$cur_path}";
      } else if ($type == ArcanistDiffChangeType::TYPE_DELETE ||
                 $type == ArcanistDiffChangeType::TYPE_MULTICOPY) {
        $old_mode = idx($change->getOldProperties(), 'unix:filemode');
        if ($old_mode) {
          $result[] = "deleted file mode {$old_mode}";
        }
      }

      if (!$is_binary) {
        $result[] = "--- {$old_target}";
        $result[] = "+++ {$cur_target}";
      }
      $result[] = $change_body;
    }
    return implode("\n", $result)."\n";
  }

  public function getChanges() {
    return $this->changes;
  }

  private function breakHunkIntoSmallHunks(ArcanistDiffHunk $hunk) {
    $context = 3;

    $results = array();
    $lines = explode("\n", $hunk->getCorpus());
    $n = count($lines);

    $old_offset = $hunk->getOldOffset();
    $new_offset = $hunk->getNewOffset();

    $ii = 0;
    $jj = 0;
    while ($ii < $n) {
      for ($jj = $ii; $jj < $n && $lines[$jj][0] == ' '; ++$jj) {
        // Skip lines until we find the first line with changes.
      }
      if ($jj >= $n) {
        break;
      }

      $hunk_start = max($jj - $context, 0);

      $old_lines = 0;
      $new_lines = 0;
      $last_change = $jj;
      for (; $jj < $n; ++$jj) {
        if ($lines[$jj][0] == ' ') {
          // NOTE: We must use "context * 2" or we may generate overlapping
          // hunks. For instance, if we have "context = 3" and four unchanged
          // lines between hunks, we'll include unchanged lines 1, 2, 3 in
          // the first hunk and 2, 3, and 4 in the second hunk -- that is, lines
          // 2 and 3 will appear twice in the patch. Some time after 1.7.3.4,
          // Git stopped cleanly applying patches with overlapping hunks, so be
          // careful to avoid generating them.
          if ($jj - $last_change > ($context * 2)) {
            break;
          }
        } else {
          $last_change = $jj;
          if ($lines[$jj][0] == '-') {
            ++$old_lines;
          } else {
            ++$new_lines;
          }
        }
      }

      $hunk_length = min($jj, $n) - $hunk_start;

      $hunk = new ArcanistDiffHunk();
      $hunk->setOldOffset($old_offset + $hunk_start - $ii);
      $hunk->setNewOffset($new_offset + $hunk_start - $ii);
      $hunk->setOldLength($hunk_length - $new_lines);
      $hunk->setNewLength($hunk_length - $old_lines);

      $corpus = array_slice($lines, $hunk_start, $hunk_length);
      $corpus = implode("\n", $corpus);
      $hunk->setCorpus($corpus);

      $results[] = $hunk;

      $old_offset += ($jj - $ii) - $new_lines;
      $new_offset += ($jj - $ii) - $old_lines;
      $ii = $jj;
    }

    return $results;
  }

  private function getOldPath(ArcanistDiffChange $change) {
    $old_path = $change->getOldPath();
    $type = $change->getType();

    if (!strlen($old_path) ||
        $type == ArcanistDiffChangeType::TYPE_ADD) {
      $old_path = null;
    }

    return $old_path;
  }

  private function getCurrentPath(ArcanistDiffChange $change) {
    $cur_path = $change->getCurrentPath();
    $type = $change->getType();

    if (!strlen($cur_path) ||
        $type == ArcanistDiffChangeType::TYPE_DELETE ||
        $type == ArcanistDiffChangeType::TYPE_MULTICOPY) {
      $cur_path = null;
    }

    return $cur_path;
  }

  private function buildHunkChanges(array $hunks) {
    $result = array();
    foreach ($hunks as $hunk) {
      $small_hunks = $this->breakHunkIntoSmallHunks($hunk);
      foreach ($small_hunks as $small_hunk) {
        $o_off = $small_hunk->getOldOffset();
        $o_len = $small_hunk->getOldLength();
        $n_off = $small_hunk->getNewOffset();
        $n_len = $small_hunk->getNewLength();
        $corpus = $small_hunk->getCorpus();

        $result[] = "@@ -{$o_off},{$o_len} +{$n_off},{$n_len} @@";
        $result[] = $corpus;
      }
    }
    return implode("\n", $result);
  }

  private function getBlob($phid) {
    if ($this->diskPath) {
      list($blob_data) = execx('tar xfO %s blobs/%s', $this->diskPath, $phid);
      return $blob_data;
    }

    if ($this->conduit) {
      echo "Downloading binary data...\n";
      $data_base64 = $this->conduit->callMethodSynchronous(
        'file.download',
        array(
          'phid' => $phid,
        ));
      return base64_decode($data_base64);
    }

    throw new Exception("Nowhere to load blob '{$phid} from!");
  }

  private function buildBinaryChange(ArcanistDiffChange $change) {
    $old_phid = $change->getMetadata('old:binary-phid', null);
    $new_phid = $change->getMetadata('new:binary-phid', null);

    $type = $change->getType();
    if ($type == ArcanistDiffChangeType::TYPE_ADD) {
      $old_null = true;
    } else {
      $old_null = false;
    }

    if ($type == ArcanistDiffChangeType::TYPE_DELETE) {
      $new_null = true;
    } else {
      $new_null = false;
    }

    if ($old_null) {
      $old_data = '';
      $old_length = 0;
      $old_sha1 = str_repeat('0', 40);
    } else {
      $old_data = $this->getBlob($old_phid);
      $old_length = strlen($old_data);
      $old_sha1 = sha1("blob {$old_length}\0{$old_data}");
    }

    if ($new_null) {
      $new_data = '';
      $new_length = 0;
      $new_sha1 = str_repeat('0', 40);
    } else {
      $new_data = $this->getBlob($new_phid);
      $new_length = strlen($new_data);
      $new_sha1 = sha1("blob {$new_length}\0{$new_data}");
    }

    $content = array();
    $content[] = "index {$old_sha1}..{$new_sha1}";
    $content[] = "GIT binary patch";

    $content[] = "literal {$new_length}";
    $content[] = $this->emitBinaryDiffBody($new_data);

    $content[] = "literal {$old_length}";
    $content[] = $this->emitBinaryDiffBody($old_data);

    return implode("\n", $content);
  }

  private function emitBinaryDiffBody($data) {
    // See emit_binary_diff_body() in diff.c for git's implementation.

    $buf = '';

    $deflated = gzcompress($data);
    $lines = str_split($deflated, 52);
    foreach ($lines as $line) {
      $len = strlen($line);
      // The first character encodes the line length.
      if ($len <= 26) {
        $buf .= chr($len + ord('A') - 1);
      } else {
        $buf .= chr($len - 26 + ord('a') - 1);
      }
      $buf .= $this->encodeBase85($line);
      $buf .= "\n";
    }

    $buf .= "\n";

    return $buf;
  }

  private function encodeBase85($data) {
    // This is implemented awkwardly in order to closely mirror git's
    // implementation in base85.c

    static $map = array(
      '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
      'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J',
      'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T',
      'U', 'V', 'W', 'X', 'Y', 'Z',
      'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j',
      'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't',
      'u', 'v', 'w', 'x', 'y', 'z',
      '!', '#', '$', '%', '&', '(', ')', '*', '+', '-',
      ';', '<', '=', '>', '?', '@', '^', '_', '`', '{',
      '|', '}', '~',
    );

    $buf = '';

    $pos = 0;
    $bytes = strlen($data);
    while ($bytes) {
      $accum = '0';
      for ($count = 24; $count >= 0; $count -= 8) {
        $val = ord($data[$pos++]);
        $val = bcmul($val, (string)(1 << $count));
        $accum = bcadd($accum, $val);
        if (--$bytes == 0) {
          break;
        }
      }
      $slice = '';
      for ($count = 4; $count >= 0; $count--) {
        $val = bcmod($accum, 85);
        $accum = bcdiv($accum, 85);
        $slice .= $map[$val];
      }
      $buf .= strrev($slice);
    }

    return $buf;
  }

}
