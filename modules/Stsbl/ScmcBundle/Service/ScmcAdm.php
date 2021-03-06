<?php

namespace Stsbl\ScmcBundle\Service;

use IServ\CoreBundle\Exception\ShellExecException;
use IServ\CoreBundle\Security\Core\SecurityHandler;
use IServ\CoreBundle\Service\Shell;
use IServ\CoreBundle\Service\Sudo as SudoService;
use IServ\CoreBundle\Util\Sudo;
use IServ\CrudBundle\Entity\FlashMessageBag;
use IServ\FileBundle\Entity\File;
use Psr\Container\ContainerInterface;
use Stsbl\ScmcBundle\Entity\Server;
use Stsbl\ScmcBundle\Security\ScmcAuth;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/*
 * The MIT License
 *
 * Copyright 2020 Felix Jacobi.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

/**
 * scmcadm service container
 *
 * @author Felix Jacobi <felix.jacobi@stsbl.de>
 * @license MIT license <https://opensource.org/licenses/MIT>
 */
class ScmcAdm implements ServiceSubscriberInterface
{
    const SCMCADM = '/usr/lib/iserv/scmcadm';
    const SCMCADM_PUTDATA = 'putdata';
    const SCMCADM_GETDATA = 'getdata';
    const SCMCADM_STOREKEY = 'storekey';
    const SCMCADM_DELETEKEY = 'deletekey';
    const SCMCADM_MASTERPASSWDEMPTY = 'masterpasswdempty';
    const SCMCADM_SETUSERPASSWD = 'setuserpasswd';
    const SCMCADM_DELETEUSERPASSWD = 'deleteuserpasswd';
    const SCMCADM_SETMASTERPASSWD = 'setmasterpasswd';
    const SCMCADM_NEWCONFIG = 'newconfig';

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var Filesystem
     */
    private $filesystem;
    
    /**
     * @var Request
     */
    private $request;
    
    /**
     * @var SecurityHandler
     */
    private $securityHandler;
    
    /**
     * @var Shell
     */
    private $shell;

    /**
     * @var ScmcAuth
     */
    private $scmcAuth;

    /**
     * The constructor.
     *
     * @param Shell $shell
     * @param RequestStack $stack
     * @param SecurityHandler $securityHandler
     * @param ScmcAuth $scmcAuth
     */
    public function __construct(Shell $shell, RequestStack $stack, SecurityHandler $securityHandler, ScmcAuth $scmcAuth)
    {
        $this->request = $stack->getCurrentRequest();
        $this->shell = $shell;
        $this->securityHandler = $securityHandler;
        $this->filesystem = new Filesystem();
        $this->scmcAuth = $scmcAuth;
    }
    
    /**
     * Creates and returns temporary directory with random path
     *
     * @return string
     */
    private function getTemporaryDirectory()
    {
        $randomNumber = rand(1000, getrandmax());
        $dirPrefix = '/tmp/stsbl-iserv-scmc-';
        $dir = $dirPrefix.$randomNumber.'/';
        
        if ($this->filesystem->exists($dir)) {
            throw new \RuntimeException('Path is unsafe, directory already exists!');
        }
        
        $this->filesystem->mkdir($dir);
        $this->filesystem->chmod($dir, 0700);
        
        if (!is_writeable($dir)) {
            throw new \RuntimeException(sprintf('%s must be writeable, it is not.', $dir));
        }
        
        return $dir;
    }

    /**
     * Execute a command and return a FlashMessageBag with STDOUT lines as
     * success messages and STDERR lines as error messages.
     * Similar to the original from HostManager, but filter out empty
     * stdout lines.
     *
     * @param string $cmd
     * @param mixed $args
     * @param mixed $stdin
     * @param array $env
     * @param  callable $filterOutputCallBack
     * @return FlashMessageBag STDOUT and STDERR contents as FlashMessageBag
     */
    private function shellMsg($cmd, $args = null, $stdin = null, $env = null, callable $filterOutputCallBack = null)
    {
        try {
            $this->shell->exec($cmd, $args, $stdin, $env);
        } catch (ShellExecException $e) {
            throw new \RuntimeException('Failed to run scmcadm!', 0, $e);
        }

        $messages = new FlashMessageBag();
        foreach ($this->shell->getOutput() as $o) {
            if ($filterOutputCallBack != null && !call_user_func_array($filterOutputCallBack, [$o])) {
                continue;
            }

            !empty($o) ? $messages->addMessage('success', $o) : null;
        }

        foreach ($this->shell->getError() as $e) {
            $messages->addMessage('error', $e);
        }

        return $messages;
    }

    /**
     * Get X-FORWARDED-FOR ip
     *
     * @return string|null
     */
    private function getIpFwd()
    {
        return $this->request->server->has('HTTP_X_FORWARDED_FOR') ? $this->request->server->get('HTTP_X_FORWARDED_FOR') : null;
    }

    /**
     * Calls scmcadm command
     *
     * @param string $command
     * @param array $args
     * @param string $arg
     * @param callable $filterOutputCallBack
     * @param array $envAppend
     * @return FlashMessageBag
     */
    public function scmcAdm($command, array $args = [], $arg = null, callable $filterOutputCallBack = null, array $envAppend = [])
    {
        array_unshift($args, self::SCMCADM, $command, $this->securityHandler->getUser()->getUsername());

        try {
            $sessionPassword = $this->scmcAuth->getScmcSessionPassword();
        } catch (\Exception $e) {
            $sessionPassword = null;
        }

        return $this->shellMsg('sudo', $args, null, array_merge([
                'SESSPW' => $this->securityHandler->getSessionPassword(),
                'IP' => $this->request->getClientIp(),
                'IPFWD' => $this->getIpFwd(),
                'SCMC_SESSIONPW' => $sessionPassword,
                'ARG' => $arg,
            ], $envAppend), $filterOutputCallBack);
    }

    /**
     * Calls scmcadm command and returns raw Shell object
     *
     * @param $command
     * @param array $args
     * @return Shell
     */
    public function scmcAdmRaw($command, array $args = [])
    {
        $shell = $this->shell;
        array_unshift($args, self::SCMCADM, $command, $this->securityHandler->getUser()->getUsername());

        try {
            $sessionPassword = $this->scmcAuth->getScmcSessionPassword();
        } catch (\Exception $e) {
            $sessionPassword = null;
        }

        try {
            $shell->exec('sudo', $args, null, [
                'SESSPW' => $this->securityHandler->getSessionPassword(),
                'IP' => $this->request->getClientIp(),
                'IPFWD' => $this->getIpFwd(),
                'SCMC_SESSIONPW' => $sessionPassword
            ]);
        } catch (ShellExecException $e) {
            throw new \RuntimeException('Failed to run scmcadm!', 0, $e);
        }

        return $shell;
    }

    /**
     * Calls getdata sub command
     *
     * @param Server $server
     * @param array $years
     * @return array First index: FlashMessageBag, Second index: Prepared Response or null
     */
    public function getData(Server $server, array $years)
    {
        $args = [$server->getId()];
        // add years on demand
        if (count($years) > 0) {
            $args[] = join(',', $years);
        }

        $ret = $this->scmcAdm(self::SCMCADM_GETDATA, $args, null, function ($o) {
            return strpos($o, 'path=') != 0;
        });
        $zipPath = null;

        foreach ($this->shell->getOutput() as $line) {
            if (preg_match('|^path=|', $line)) {
                $zipPath = preg_replace('|^path=|', '', $line);
                break;
            }
        }

        if ($zipPath === null) {
            $ret->addMessage('error', _('Something went wrong.'));
            return [$ret, null];
        }

        $zipContent = file_get_contents($zipPath);
        $this->filesystem->remove($zipPath);

        $quoted = sprintf('"%s"', addcslashes('zeugnis-download-'.date('d-m-Y-G-i-s').'.zip', '"\\'));

        $response = new Response($zipContent);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename='.$quoted);

        return [$ret, $response];
    }

    /**
     * Calls putdata sub command
     *
     * @param Server $server
     * @param array $files
     * @param array $years
     * @return FlashMessageBag
     */
    public function putData(Server $server, array $files, array $years = null)
    {
        if (!isset($files[0]) || null === $files[0]) {
            throw new \InvalidArgumentException('File not supplied as first array argument.');
        }


        /* @var $file \IServ\FilesystemBundle\Model\File */
        $file = $files[0];

        if ($file instanceof File) {
            /**
             * PHP7 does have the fileinfo module in core
             * (sudo_php runs with minimized configuration)
             */
            if (PHP_MAJOR_VERSION >= 7) {
                Sudo::dl('fileinfo.so');
            }

            /* @var $file File */
            /* @var $fileInfo \finfo */
            $fileInfo = Sudo::_new('finfo');
            $mimeType = $fileInfo->file(sprintf('%s/%s', $this->securityHandler->getUser()->getHome(), $file->getFilename()), FILEINFO_MIME_TYPE);
        } else {
            $mimeType = $file->getMimetype();
        }

        if ($mimeType != 'application/zip') {
            $bag = new FlashMessageBag();
            $bag->addMessage('error', _('You have to upload a zip file!'));
            return $bag;
        }

        $dir = $this->getTemporaryDirectory();
        $filePath = $dir.'upload.zip';

        if ($file instanceof File) {
            $this->container->get(SudoService::class);
            /* @var $file File */
            $content = Sudo::file_get_contents(sprintf('%s/%s', $this->securityHandler->getUser()->getHome(), $file->getFilename()));
        } else {
            $content = $file->read($filePath);
            // remove temp file
            $file->delete();
        }

        $handle = fopen($filePath, 'w');
        fwrite($handle, $content);
        fclose($handle);

        $args = [$server->getId(), $filePath];
        // add years on demand1
        if (count($years) > 0) {
            $args[] = join(',', $years);
        }
        
        $ret = $this->scmcAdm(self::SCMCADM_PUTDATA, $args);
        
        if ($this->filesystem->exists($dir)) {
            $this->filesystem->remove($dir);
        }
        
        return $ret;
    }

    /**
     * Calls storekey sub command
     *
     * @param Server $server
     * @param UploadedFile $file
     * @return FlashMessageBag
     */
    public function storeKey(Server $server, UploadedFile $file)
    {
        $dir = $this->getTemporaryDirectory();
        $filePath = $dir.'key';
        $file->move($dir, 'key');

        $args = [$server->getId(), $filePath];

        $ret = $this->scmcAdm(self::SCMCADM_STOREKEY, $args);

        if ($this->filesystem->exists($dir)) {
            $this->filesystem->remove($dir);
        }

        return $ret;
    }

    /**
     * Calls deletekey sub command
     *
     * @param Server $server
     * @return FlashMessageBag
     */
    public function deleteKey(Server $server)
    {
        $args = [$server->getId()];
        return $this->scmcAdm(self::SCMCADM_DELETEKEY, $args);
    }

    /**
     * Calls masterpasswdempty sub command
     *
     * @return bool
     */
    public function masterPasswdEmpty()
    {
        $res = $this->scmcAdmRaw(self::SCMCADM_MASTERPASSWDEMPTY);

        foreach ($res->getOutput() as $o) {
            if (preg_match('/^res=(.*)$/', $o, $m)) {
                if ($m[1] === "true") {
                    return true;
                } elseif ($m[1] === "false") {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Calls setuserpasswd sub command
     *
     * @param string $user
     * @param string $password
     * @return FlashMessageBag
     */
    public function setUserPasswd($user, $password)
    {
        return $this->scmcAdm(self::SCMCADM_SETUSERPASSWD, [$user], $password);
    }

    /**
     * Calls deleteuserpasswd sub command
     *
     * @param string $user
     * @return FlashMessageBag
     */
    public function deleteUserPasswd($user)
    {
        return $this->scmcAdm(self::SCMCADM_DELETEUSERPASSWD, [$user]);
    }

    /**
     * Calls setmasterpasswd sub command
     *
     * @param string $newPassword
     * @param string $oldPassword
     * @return FlashMessageBag
     */
    public function setMasterPasswd($newPassword, $oldPassword = null)
    {
        return $this->scmcAdm(self::SCMCADM_SETMASTERPASSWD, [], null, null, [
            'SCMC_NEWMASTERPW' => $newPassword,
            'SCMC_OLDMASTERPW' => $oldPassword
        ]);
    }

    /**
     * Calls newconfig sub command
     *
     * @return FlashMessageBag
     */
    public function newConfig()
    {
        return $this->scmcAdm(self::SCMCADM_NEWCONFIG);
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedServices()
    {
        return [SudoService::class];
    }

    /**
     * @required
     */
    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }
}
