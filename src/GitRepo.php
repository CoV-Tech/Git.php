<?php

namespace Kbjr\Git;

use Exception;

/**
 * Git Repository Interface Class
 *
 * This class enables the creating, reading, and manipulation
 * of a git repository
 *
 * @class  GitRepo
 */
class GitRepo {

	protected ?string $repoPath = null;
	protected bool $bare = false;
	protected array $envopts = [];

    /**
     * Create a new git repository
     *
     * Accepts a creation path, and, optionally, a source path
     *
     * @access  public
     * @param string  repository path
     * @param string|null  directory to source
     * @param bool    $remoteSource
     * @param string|null  reference path
     * @return  GitRepo
     * @throws Exception
     */
	public static function createNew(string $repoPath, ?string $source = null, bool $remoteSource = false, ?string $reference = null): self
    {
		if (is_dir($repoPath) && file_exists($repoPath . "/.git")) {
			throw new Exception('"' . $repoPath . '" is already a git repository');
		} else {
			$repo = new self($repoPath, true, false);
			if (is_string($source)) {
				if ($remoteSource) {
					if (isset($reference)) {
						if (!is_dir($reference) || !is_dir($reference.'/.git')) {
							throw new Exception('"' . $reference . '" is not a git repository. Cannot use as reference.');
						} else if (strlen($reference)) {
							$reference = realpath($reference);
							$reference = "--reference $reference";
						}
					}
					$repo->cloneRemote($source, $reference);
				} else {
					$repo->cloneFrom($source);
				}
			} else {
				$repo->run('init');
			}
			return $repo;
		}
	}

    /**
     * Constructor
     *
     * Accepts a repository path
     *
     * @access  public
     * @param string|null  repository path
     * @param bool    create if not exists?
     * @return  void
     * @throws Exception
     */
	public function __construct(?string $repoPath = null, bool $createNew = false, bool $_init = true)
	{
		if (is_string($repoPath)) {
			$this->setRepoPath($repoPath, $createNew, $_init);
		}
	}

    /**
     * Set the repository's path
     *
     * Accepts the repository path
     *
     * @access  public
     * @param string  repository path
     * @param bool    create if not exists?
     * @param bool    initialize new Git repo if not exists?
     * @return  void
     * @throws Exception
     */
	public function setRepoPath(string $repoPath, bool $createNew = false, bool $_init = true)
    {
        if ($newPath = realpath($repoPath)) {
            $repoPath = $newPath;
            if (is_dir($repoPath)) {
                // Is this a work tree?
                if (file_exists($repoPath . "/.git")) {
                    $this->repoPath = $repoPath;
                    $this->bare = false;
                // Is this a bare repo?
                } else if (is_file($repoPath . "/config")) {
                  $parse_ini = parse_ini_file($repoPath . "/config");
                    if ($parse_ini['bare']) {
                        $this->repoPath = $repoPath;
                        $this->bare = true;
                    }
                } else {
                    if ($createNew) {
                        $this->repoPath = $repoPath;
                        if ($_init) {
                            $this->run('init');
                        }
                    } else {
                        throw new Exception('"' . $repoPath . '" is not a git repository');
                    }
                }
            } else {
                throw new Exception('"' . $repoPath . '" is not a directory');
            }
        } else {
            if ($createNew) {
                if ($parent = realpath(dirname($repoPath))) {
                    mkdir($repoPath);
                    $this->repoPath = $repoPath;
                    if ($_init) $this->run('init');
                } else {
                    throw new Exception('cannot create repository in non-existent directory');
                }
            } else {
                throw new Exception('"' . $repoPath . '" does not exist');
            }
        }
    }

    /**
     * Get the path to the git repo directory (eg. the ".git" directory)
     *
     * @access public
     * @return string|null
     * @throws Exception
     */
	public function gitDirectoryPath(): ?string
    {
		if ($this->bare) {
			return $this->repoPath;
		} else if (is_dir($this->repoPath . "/.git")) {
			return $this->repoPath . "/.git";
		} else if (is_file($this->repoPath . "/.git")) {
			$gitFile = file_get_contents($this->repoPath . "/.git");
			if(mb_ereg("^gitdir: (.+)$", $gitFile, $matches)){
				if($matches[1]) {
					$relGitPath = $matches[1];
					return $this->repoPath . "/" . $relGitPath;
				}
			}
		}
		throw new Exception('could not find git dir for ' . $this->repoPath . '.');
	}

	/**
	 * Tests if git is installed
	 *
	 * @access  public
	 * @return  bool
	 */
	public function testGit(): bool
    {
		$descriptor_spec = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pipes = [];
		$resource = proc_open(Git::getBin(), $descriptor_spec, $pipes);

		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));

		return ($status != 127);
	}

    /**
     * Run a command in the git repository
     *
     * Accepts a shell command to run
     *
     * @access  protected
     * @param string  command to run
     * @return  string
     * @throws Exception
     */
	protected function runCommand($command): string
    {
        $descriptor_spec = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pipes = [];
		/* Depending on the value of variables_order, $_ENV may be empty.
		 * In that case, we have to explicitly set the new variables with
		 * putenv, and call proc_open with env=null to inherit the reset
		 * of the system.
		 *
		 * This is kind of crappy because we cannot easily restore just those
		 * variables afterwards.
		 *
		 * If $_ENV is not empty, then we can just copy it and be done with it.
		 */
		if(count($_ENV) === 0) {
			$env = NULL;
			foreach($this->envopts as $k => $v) {
				putenv(sprintf("%s=%s",$k,$v));
			}
		} else {
			$env = array_merge($_ENV, $this->envopts);
		}
		$cwd = $this->repoPath;
		$resource = proc_open($command, $descriptor_spec, $pipes, $cwd, $env);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach ($pipes as $pipe) {
			fclose($pipe);
		}

		$status = trim(proc_close($resource));
		if ($status) throw new Exception($stderr . "\n" . $stdout); //Not all errors are printed to stderr, so include std out as well.

		return $stdout;
	}

    /**
     * Run a git command in the git repository
     *
     * Accepts a git command to run
     *
     * @access  public
     * @param string  command to run
     * @return  string
     * @throws Exception
     */
	public function run(string $command): string
    {
		return $this->runCommand(Git::getBin()." ".$command);
	}

    /**
     * Runs a 'git status' call
     *
     * Accept a convert to HTML bool
     *
     * @access public
     * @param bool  return string with <br />
     * @return string
     * @throws Exception
     */
	public function status(bool $html = false): string
    {
		$msg = $this->run("status");
		if ($html) {
			$msg = str_replace("\n", "<br />", $msg);
		}
		return $msg;
	}

    /**
     * Runs a `git add` call
     *
     * Accepts a list of files to add
     *
     * @access  public
     * @param string|string[]   files to add
     * @return  string
     * @throws Exception
     */
	public function add(array|string $files = "*"): string
    {
		if (is_array($files)) {
			$files = '"'.implode('" "', $files).'"';
		}
		return $this->run("add $files -v");
	}

    /**
     * Runs a `git rm` call
     *
     * Accepts a list of files to remove
     *
     * @access  public
     * @param string|string[]    files to remove
     * @param Boolean  use the --cached flag?
     * @return  string
     * @throws Exception
     */
	public function rm(array|string$files = "*", bool $cached = false): string
    {
		if (is_array($files)) {
			$files = '"'.implode('" "', $files).'"';
		}
		return $this->run("rm ".($cached ? '--cached ' : '').$files);
	}


    /**
     * Runs a `git commit` call
     *
     * Accepts a commit message string
     *
     * @access  public
     * @param string  commit message
     * @param boolean  should all files be committed automatically (-a flag)
     * @return  string
     * @throws Exception
     */
	public function commit(string $message = "", bool $commitAll = true): string
    {
		$flags = $commitAll ? '-av' : '-v';
		return $this->run("commit " . $flags . " -m " . escapeshellarg($message));
	}

    /**
     * Runs a `git clone` call to clone the current repository
     * into a different directory
     *
     * Accepts a target directory
     *
     * @access  public
     * @param string  target directory
     * @return  string
     * @throws Exception
     */
	public function cloneTo(string $target): string
    {
		return $this->run("clone --local " . $this->repoPath . " $target");
	}

    /**
     * Runs a `git clone` call to clone a different repository
     * into the current repository
     *
     * Accepts a source directory
     *
     * @access  public
     * @param string  source directory
     * @return  string
     * @throws Exception
     */
	public function cloneFrom(string $source): string
    {
		return $this->run("clone --local $source " . $this->repoPath);
	}

    /**
     * Runs a `git clone` call to clone a remote repository
     * into the current repository
     *
     * Accepts a source url
     *
     * @access  public
     * @param string  source url
     * @param string  reference path
     * @return  string
     * @throws Exception
     */
	public function cloneRemote(string $source, string $reference): string
    {
		return $this->run("clone $reference $source " . $this->repoPath);
	}

    /**
     * Runs a `git clean` call
     *
     * Accepts a remove directories flag
     *
     * @access  public
     * @param bool    delete directories?
     * @param bool    force clean?
     * @return  string
     * @throws Exception
     */
	public function clean(bool $dirs = false, bool $force = false): string
    {
		return $this->run("clean".(($force) ? " -f" : "").(($dirs) ? " -d" : ""));
	}

    /**
     * Runs a `git branch` call
     *
     * Accepts a name for the branch
     *
     * @access  public
     * @param string  branch name
     * @return  string
     * @throws Exception
     */
	public function createBranch(string $branch): string
    {
		return $this->run("branch " . escapeshellarg($branch));
	}

    /**
     * Runs a `git branch -[d|D]` call
     *
     * Accepts a name for the branch
     *
     * @access  public
     * @param string  branch name
     * @param bool $force
     * @return  string
     * @throws Exception
     */
	public function deleteBranch(string $branch, bool $force = false): string
    {
		return $this->run("branch ".(($force) ? '-D' : '-d')." $branch");
	}

    /**
     * Runs a `git branch` call
     *
     * @access  public
     * @param bool    keep asterisk mark on active branch
     * @return  array
     * @throws Exception
     */
	public function listBranches(bool $keep_asterisk = false): array
    {
		$branchArray = explode("\n", $this->run("branch"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if (! $keep_asterisk) {
				$branch = str_replace("* ", "", $branch);
			}
			if ($branch == "") {
				unset($branchArray[$i]);
			}
		}
		return $branchArray;
	}

    /**
     * Lists remote branches (using `git branch -r`).
     *
     * Also strips out the HEAD reference (e.g. "origin/HEAD -> origin/master").
     *
     * @access  public
     * @return  array
     * @throws Exception
     */
	public function listRemoteBranches(): array
    {
		$branchArray = explode("\n", $this->run("branch -r"));
		foreach($branchArray as $i => &$branch) {
			$branch = trim($branch);
			if ($branch == "" || str_contains($branch, 'HEAD -> ')) {
				unset($branchArray[$i]);
			}
		}
		return $branchArray;
	}

    /**
     * Returns name of active branch
     *
     * @access  public
     * @param bool    keep asterisk mark on branch name
     * @return  string
     * @throws Exception
     */
	public function activeBranch(bool $keep_asterisk = false): string
    {
		$branchArray = $this->listBranches(true);
		$active_branch = preg_grep("/^\*/", $branchArray);
        /** @noinspection PhpArrayIndexResetIsUnnecessaryInspection */
        reset($active_branch);
		if ($keep_asterisk) {
			return current($active_branch);
		} else {
			return str_replace("* ", "", current($active_branch));
		}
	}

    /**
     * Runs a `git checkout` call
     *
     * Accepts a name for the branch
     *
     * @access  public
     * @param string  branch name
     * @return  string
     * @throws Exception
     */
	public function checkout(string $branch): string
    {
		return $this->run("checkout " . escapeshellarg($branch));
	}

    /**
     * Runs a `git merge` call
     *
     * Accepts a name for the branch to be merged
     *
     * @access  public
     * @param string $branch
     * @return  string
     * @throws Exception
     */
	public function merge(string $branch): string
    {
		return $this->run("merge " . escapeshellarg($branch) . " --no-ff");
	}

    /**
     * Runs a git fetch on the current branch
     *
     * @access  public
     * @return  string
     * @throws Exception
     */
	public function fetch(): string
    {
		return $this->run("fetch");
	}

    /**
     * Add a new tag on the current position
     *
     * Accepts the name for the tag and the message
     *
     * @param string $tag
     * @param string|null $message
     * @return string
     * @throws Exception
     */
	public function addTag(string $tag, ?string $message = null): string
    {
		if (is_null($message)) {
			$message = $tag;
		}
		return $this->run("tag -a $tag -m " . escapeshellarg($message));
	}

    /**
     * List all the available repository tags.
     *
     * Optionally, accept a shell wildcard pattern and return only tags matching it.
     *
     * @access    public
     * @param string|null $pattern Shell wildcard pattern to match tags against.
     * @return    array                Available repository tags.
     * @throws Exception
     */
	public function listTags(?string $pattern = null): array
    {
		$tagArray = explode("\n", $this->run("tag -l $pattern"));
		foreach ($tagArray as $i => &$tag) {
			$tag = trim($tag);
			if (empty($tag)) {
				unset($tagArray[$i]);
			}
		}

		return $tagArray;
	}

    /**
     * Push specific branch (or all branches) to a remote
     *
     * Accepts the name of the remote and local branch.
     * If omitted, the command will be "git push", and therefore will take
     * on the behavior of your "push.default" configuration setting.
     *
     * @param string $remote
     * @param string $branch
     * @return string
     * @throws Exception
     */
	public function push(string $remote = "", string $branch = ""): string
    {
        //--tags removed since this was preventing branches from being pushed (only tags were)
		return $this->run("push $remote $branch");
	}

    /**
     * Pull specific branch from remote
     *
     * Accepts the name of the remote and local branch.
     * If omitted, the command will be "git pull", and therefore will take on the
     * behavior as-configured in your clone / environment.
     *
     * @param string $remote
     * @param string $branch
     * @return string
     * @throws Exception
     */
	public function pull(string $remote = "", string $branch = ""): string
    {
		return $this->run("pull $remote $branch");
	}

    /**
     * List log entries.
     *
     * @param string|null $format
     * @param bool $fullDiff
     * @param string|null $filepath
     * @param bool $follow
     * @return string
     * @throws Exception
     */
	public function log(?string $format = null, bool $fullDiff = false, ?string $filepath = null, bool $follow = false): string
    {
		$diff = "";

		if ($fullDiff){
			$diff = "--full-diff -p ";
		}

		if ($follow){
		    // Can't use full-diff with follow
		    $diff = "--follow -- ";
		}

		if ($format === null) {
			return $this->run('log ' . $diff . $filepath);
		} else {
			return $this->run('log --pretty=format:"' . $format . '" ' . $diff . $filepath);
		}
	}

    /**
     * Sets the project description.
     *
     * @param string $new
     * @throws Exception
     */
	public function setDescription(string $new)
	{
		$path = $this->gitDirectoryPath();
		file_put_contents($path . "/description", $new);
	}

    /**
     * Gets the project description.
     *
     * @return string
     * @throws Exception
     */
	public function getDescription(): string
    {
		$path = $this->gitDirectoryPath();
		return file_get_contents($path . "/description");
	}

	/**
	 * Sets custom environment options for calling Git
	 *
	 * @param string key
	 * @param string value
	 */
	public function setenv($key, $value)
	{
		$this->envopts[$key] = $value;
	}
}
