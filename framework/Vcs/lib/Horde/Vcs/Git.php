<?php
/**
 * Horde_Vcs_Git implementation.
 *
 * Constructor args:
 * <pre>
 * 'sourceroot': The source root for this repository
 * 'paths': Hash with the locations of all necessary binaries: 'git'
 * </pre>
 *
 * Copyright 2008-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Vcs
 */
class Horde_Vcs_Git extends Horde_Vcs
{
    /**
     * Does driver support patchsets?
     *
     * @var boolean
     */
    protected $_patchsets = true;

    /**
     * Does driver support branches?
     *
     * @var boolean
     */
    protected $_branches = true;

    /**
     * The available diff types.
     *
     * @var array
     */
    protected $_diffTypes = array('unified');

    /**
     * TODO
     */
    public function isValidRevision($rev)
    {
        return $rev && preg_match('/^[a-f0-9]+$/i', $rev);
    }

    /**
     * TODO
     */
    public function isFile($where)
    {
        $where = str_replace($this->sourceroot() . '/', '', $where);
        $command = $this->getCommand() . ' ls-tree master ' . escapeshellarg($where) . ' 2>&1';
        $entry = array();
        exec($command, $entry);
        $data = explode(' ', $entry[0]);
        return ($data[1] == 'blob');
    }

    /**
     * TODO
     */
    public function getCommand()
    {
        return escapeshellcmd($this->getPath('git')) . ' --git-dir=' . escapeshellarg($this->sourceroot());
    }

    /**
     * TODO
     *
     * @throws Horde_Vcs_Exception
     */
    public function annotate($fileob, $rev)
    {
        $this->assertValidRevision($rev);

        $command = $this->getCommand() . ' blame -p ' . escapeshellarg($rev) . ' -- ' . escapeshellarg($fileob->queryModulePath()) . ' 2>&1';
        $pipe = popen($command, 'r');
        if (!$pipe) {
            throw new Horde_Vcs_Exception('Failed to execute git annotate: ' . $command);
        }

        $curr_rev = null;
        $db = $lines = array();
        $lines_group = $line_num = 0;

        while (!feof($pipe)) {
            $line = rtrim(fgets($pipe, 4096));

            if (!$line || ($line[0] == "\t")) {
                if ($lines_group) {
                    $lines[] = array(
                        'author' => $db[$curr_rev]['author'] . ' ' . $db[$curr_rev]['author-mail'],
                        'date' => $db[$curr_rev]['author-time'],
                        'line' => $line ? substr($line, 1) : '',
                        'lineno' => $line_num++,
                        'rev' => $curr_rev
                    );
                    --$lines_group;
                }
            } elseif ($line != 'boundary') {
                if ($lines_group) {
                    list($prefix, $linedata) = explode(' ', $line, 2);
                    switch ($prefix) {
                    case 'author':
                    case 'author-mail':
                    case 'author-time':
                    //case 'author-tz':
                        $db[$curr_rev][$prefix] = trim($linedata);
                        break;
                    }
                } else {
                    $curr_line = explode(' ', $line);
                    $curr_rev = $curr_line[0];
                    $line_num = $curr_line[2];
                    $lines_group = isset($curr_line[3]) ? $curr_line[3] : 1;
                }
            }
        }

        pclose($pipe);

        return $lines;
    }

    /**
     * Function which returns a file pointing to the head of the requested
     * revision of a file.
     *
     * @param string $fullname  Fully qualified pathname of the desired file
     *                          to checkout
     * @param string $rev       Revision number to check out
     *
     * @return resource  A stream pointer to the head of the checkout.
     * @throws Horde_Vcs_Exception
     */
    public function checkout($file, $rev)
    {
        $this->assertValidRevision($rev);

        $file_ob = $this->getFileObject($file);

        if ($pipe = popen($this->getCommand() . ' cat-file blob ' . $file_ob->getHashForRevision($rev) . ' 2>&1', VC_WINDOWS ? 'rb' : 'r')) {
            return $pipe;
        }

        throw new Horde_Vcs_Exception('Couldn\'t perform checkout of the requested file');
    }

    /**
     * Create a range of revisions between two revision numbers.
     *
     * @param Horde_Vcs_File $file  The desired file.
     * @param string $r1            The initial revision.
     * @param string $r2            The ending revision.
     *
     * @return array  The revision range, or empty if there is no straight
     *                line path between the revisions.
     */
    public function getRevisionRange($file, $r1, $r2)
    {
        $revs = $this->_getRevisionRange($file, $r1, $r2);
        return empty($revs)
            ? array_reverse($this->_getRevisionRange($file, $r2, $r1))
            : $revs;
    }

    /**
     * TODO
     */
    protected function _getRevisionRange($file, $r1, $r2)
    {
        $cmd = $this->getCommand() . ' rev-list ' . escapeshellarg($r1 . '..' . $r2) . ' -- ' . escapeshellarg($file->queryModulePath());
        $revs = array();

        exec($cmd, $revs);
        return array_map('trim', $revs);
    }

    /**
     * Obtain the differences between two revisions of a file.
     *
     * @param Horde_Vcs_File $file  The desired file.
     * @param string $rev1          Original revision number to compare from.
     * @param string $rev2          New revision number to compare against.
     * @param array $opts           The following optional options:
     * <pre>
     * 'num' - (integer) DEFAULT: 3
     * 'type' - (string) DEFAULT: 'unified'
     * 'ws' - (boolean) DEFAULT: true
     * </pre>
     *
     * @return string  The diff text.
     */
    protected function _diff($file, $rev1, $rev2, $opts)
    {
        $diff = array();
        $flags = '';

        if (!$opts['ws']) {
            $flags .= ' -b -w ';
        }

        switch ($opts['type']) {
        case 'unified':
            $flags .= '--unified=' . escapeshellarg((int)$opts['num']);
            break;
        }

        // @TODO: add options for $hr options - however these may not
        // be compatible with some diffs.
        $command = $this->getCommand() . ' diff -M -C ' . $flags . ' --no-color ' . escapeshellarg($rev1 . '..' . $rev2) . ' -- ' . escapeshellarg($file->queryModulePath()) . ' 2>&1';

        exec($command, $diff, $retval);

        return $diff;
    }

    /**
     * Returns an abbreviated form of the revision, for display.
     *
     * @param string $rev  The revision string.
     *
     * @return string  The abbreviated string.
     */
    public function abbrev($rev)
    {
        return substr($rev, 0, 7) . '[...]';
    }

}

/**
 * Horde_Vcs_Git directory class.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Vcs
 */
class Horde_Vcs_Directory_Git extends Horde_Vcs_Directory
{
    /**
     * Create a Directory object to store information about the files in a
     * single directory in the repository.
     *
     * @param Horde_Vcs $rep  The Repository object this directory is part of.
     * @param string $dn      Path to the directory.
     * @param array $opts     TODO
     *
     * @throws Horde_Vcs_Exception
     */
    public function __construct($rep, $dn, $opts = array())
    {
        parent::__construct($rep, $dn, $opts);

        // @TODO For now, we're browsing master
        $branch = 'master';

        // @TODO can use this to see if we have a valid cache of the tree at this revision

        $dir = $this->queryDir();
        if (substr($dir, 0, 1) == '/') {
            $dir = substr($dir, 1);
        }
        if (strlen($dir) && substr($dir, -1) != '/') {
            $dir .= '/';
        }

        $cmd = $rep->getCommand() . ' ls-tree --full-name ' . escapeshellarg($branch) . ' ' . escapeshellarg($dir) . ' 2>&1';

        $dir = popen($cmd, 'r');
        if (!$dir) {
            throw new Horde_Vcs_Exception('Failed to execute git ls-tree: ' . $cmd);
        }

        // Create two arrays - one of all the files, and the other of
        // all the dirs.
        while (!feof($dir)) {
            $line = chop(fgets($dir, 1024));
            if (!strlen($line)) {
                continue;
            }

            list( ,$type, , $file) = preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($type == 'tree') {
                $this->_dirs[] = basename($file);
            } else {
                $this->_files[] = $rep->getFileObject($file, array('quicklog' => !empty($opts['quicklog'])));
            }
        }

        pclose($dir);
    }

}

/**
 * Horde_Vcs_Git file class.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Vcs
 */
class Horde_Vcs_File_Git extends Horde_Vcs_File
{
    /**
     * @var array
     */
    protected $_revlist = array();

    /**
     * Create a repository file object, and give it information about
     * what its parent directory and repository objects are.
     *
     * @param TODO $rep    TODO
     * @param string $fl   Full path to this file.
     * @param array $opts  TODO
     *
     * @throws Horde_Vcs_Exception
     */
    public function __construct($rep, $fl, $opts = array())
    {
        parent::__construct($rep, $fl, $opts);

        $branch_list = $revs = $tmp = array();

        /* Add branch information. */
        $cmd = $rep->getCommand() . ' show-ref --heads';
        exec($cmd, $branch_list);

        foreach ($branch_list as $val) {
            $line = explode(' ', trim($val), 2);
            $this->_branches[substr($line[1], strrpos($line[1], '/') + 1)] = $line[0];
        }

        /* Get the list of revisions.  Need to get all revisions, not just
         * those on $this->_branch, for branch determination reasons. */
        $cmd = $rep->getCommand() . ' rev-list --branches --parents -- ' . escapeshellarg($this->queryModulePath()) . ' 2>&1';
        exec($cmd, $revs);
        if (stripos($revs[0], 'fatal') === 0) {
            throw new Horde_Vcs_Exception($revs);
        }

        /* $revs format: revision parent */
        foreach ($revs as $val) {
            $line = explode(' ', trim($val), 2);
            $this->_revs[] = $line[0];

            if (isset($tmp[$line[0]])) {
                foreach ($tmp[$line[0]] as $val2) {
                    $this->_revlist[$val2][] = $line[0];
                }
            } else {
                $branch = array_search($line[0], $this->_branches);
                $this->_revlist[$branch] = array($line[0]);
                $tmp[$line[0]] = array($branch);
            }

            if (isset($line[1])) {
                if (!isset($tmp[$line[1]])) {
                    $tmp[$line[1]] = array();
                }
                $tmp[$line[1]] = array_merge($tmp[$line[1]], $tmp[$line[0]]);
            }

            if ((!$this->_quicklog || empty($this->_logs)) &&
                (empty($this->_branch) ||
                 in_array($this->_branch, $tmp[$line[0]]))) {
                $this->_logs[$line[0]] = $rep->getLogObject($this, $line[0]);
            }
        }
    }

    /**
     * Get the hash name for this file at a specific revision.
     *
     * @param string $rev  Revision string.
     *
     * @return string  Commit hash.
     */
    public function getHashForRevision($rev)
    {
        return $this->_logs[$rev]->getHashForPath($this->queryModulePath());
    }

    /**
     * Return the name of this file relative to its sourceroot.
     *
     * @return string  Pathname relative to the sourceroot.
     */
    public function queryModulePath()
    {
        return ($this->_dir == '.')
            ? $this->_name
            : parent::queryModulePath();
    }

    /**
     * TODO
     */
    public function getBranchList()
    {
        return $this->_revlist;
    }

    /**
     * TODO
     */
    public function queryBranch($rev)
    {
        $branches = array();

        foreach (array_keys($this->_revlist) as $val) {
            if (array_search($rev, $this->_revlist[$val]) !== false) {
                $branches[] = $val;
            }
        }

        return $branches;
    }

    /**
     * Return the "base" filename (i.e. the filename needed by the various
     * command line utilities).
     *
     * @return string  A filename.
     */
    public function queryPath()
    {
        return $this->queryModulePath();
    }

}

/**
 * Horde_Vcs_Git log class.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Vcs
 */
class Horde_Vcs_Log_Git extends Horde_Vcs_Log
{
    /**
     * @var string
     */
    protected $_parent = null;

    /**
     * @var array
     */
    protected $_files = array();

    /**
     * Constructor.
     *
     * @throws Horde_Vcs_Exception
     */
    public function __construct($rep, $fl, $rev)
    {
        parent::__construct($rep, $fl, $rev);

        // @TODO use Commit, CommitDate, and Merge properties
        $cmd = $rep->getCommand() . ' whatchanged --no-color --pretty=format:"Rev:%H%nParents:%P%nAuthor:%an <%ae>%nAuthorDate:%at%nRefs:%d%n%n%s%n%b" --no-abbrev -n 1 ' . $rev;
        $pipe = popen($cmd, 'r');
        if (!is_resource($pipe)) {
            throw new Horde_Vcs_Exception('Unable to run ' . $cmd . ': ' . error_get_last());
        }

        $line = trim(fgets($pipe));
        while ($line != '') {
            list($key, $value) = explode(':', $line, 2);
            $value = trim($value);

            switch (trim($key)) {
            case 'Rev':
                if ($rev != $value) {
                    fclose($pipe);
                    throw new Horde_Vcs_Exception('Expected ' . $rev . ', got ' . $value);
                }
                break;

            case 'Parents':
                // @TODO: More than 1 parent?
                $this->_parent = $value;
                break;

            case 'Author':
                $this->_author = $value;
                break;

            case 'AuthorDate':
                $this->_date = $value;
                break;

            case 'Refs':
                if ($value) {
                    $value = substr($value, 1, -1);
                    foreach (explode(',', $value) as $val) {
                        $val = trim($val);
                        if (strpos($val, 'refs/tags/') === 0) {
                            $this->_tags[] = substr($val, 10);
                        }
                    }
                    if (!empty($this->_tags)) {
                        sort($this->_tags);
                    }
                }
                break;
            }

            $line = trim(fgets($pipe));
        }

        $log = '';
        $line = fgets($pipe);
        while (substr($line, 0, 1) != ':') {
            $log .= $line;
            $line = fgets($pipe);
        }
        $this->_log = trim($log);

        // Build list of files in this revision. The format of these lines is
        // documented in the git diff-tree documentation:
        // http://www.kernel.org/pub/software/scm/git/docs/git-diff-tree.html
        while ($line) {
            preg_match('/:(\d+) (\d+) (\w+) (\w+) (.+)\t(.+)(\t(.+))?/', $line, $matches);
            $this->_files[$matches[6]] = array(
                'srcMode' => $matches[1],
                'dstMode' => $matches[2],
                'srcSha1' => $matches[3],
                'dstSha1' => $matches[4],
                'status' => $matches[5],
                'srcPath' => $matches[6],
                'dstPath' => isset($matches[7]) ? $matches[7] : ''
            );

            $line = fgets($pipe);
        }

        fclose($pipe);
    }

    /**
     * TODO
     */
    public function getHashForPath($path)
    {
        return $this->_files[$path]['dstSha1'];
    }

    /**
     * TODO
     */
    public function queryBranch()
    {
        return $this->_file->queryBranch($this->_rev);
    }

    /**
     * TODO
     */
    public function queryFiles()
    {
        return $this->_files;
    }

    /**
     * TODO
     */
    public function queryParent()
    {
        return $this->_parent;
    }

}

/**
 * Horde_Vcs_Git Patchset class.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Michael Slusarz <slusarz@horde.org>
 * @package Horde_Vcs
 */
class Horde_Vcs_Patchset_Git extends Horde_Vcs_Patchset
{
    /**
     * Constructor
     *
     * @param Horde_Vcs $rep  A Horde_Vcs repository object.
     * @param string $file    The filename to create patchsets for.
     */
    public function __construct($rep, $file)
    {
        $fileOb = $rep->getFileObject($file);

        foreach ($fileOb->queryLogs() as $rev => $log) {
            $this->_patchsets[$rev] = array(
                'date' => $log->queryDate(),
                'author' => $log->queryAuthor(),
                'branches' => $log->queryBranch(),
                'tags' => $log->queryTags(),
                'log' => $log->queryLog(),
                'members' => array()
            );

            $ps = &$this->_patchsets[$rev];

            foreach ($log->queryFiles() as $file) {
                $to = $rev;
                $status = 0;

                switch ($file['status']) {
                case 'A':
                    $from = null;
                    $status = self::INITIAL;
                    break;

                case 'D':
                    $from = $to;
                    $to = self::DEAD;
                    break;

                default:
                    $from = $log->queryParent();
                    break;
                }

                $ps['members'][] = array(
                    'file' => $file['srcPath'],
                    'from' => $from,
                    'status' => $status,
                    'to' => $to
                );
            }
        }
    }

}
