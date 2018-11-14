<?php
// src/Stsbl/SchoolCertificateManagerConnectorBundle/Controller/SecurityController.php
namespace Stsbl\SchoolCertificateManagerConnectorBundle\Controller;

use Braincrafted\Bundle\BootstrapBundle\Form\Type\FormActionsType;
use IServ\CoreBundle\Controller\PageController;
use IServ\CoreBundle\Traits\LoggerTrait;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Stsbl\SchoolCertificateManagerConnectorBundle\Traits\LoggerInitializationTrait;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;

/*
 * The MIT License
 *
 * Copyright 2018 Felix Jacobi.
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
 * School Certificate Manager Connector Login/Logout
 *
 * @author Felix Jacobi <felix.jacobi@stsbl.de>
 * @license MIT license <https://opensource.org/licenses/MIT>
 * @Route("scmc", schemes="https")
 * @Security("is_granted('PRIV_SCMC_ACCESS_FRONTEND')")
 */
class SecurityController extends PageController 
{
    use FormTrait, LoggerTrait, LoggerInitializationTrait;

    /**
     * Displays login form
     *
     * @param Request $request
     * @return array|Response
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\TransactionRequiredException
     * @throws \IServ\CoreBundle\Exception\ShellExecException
     *
     * @Route("/login", name="manage_scmc_login")
     * @Template("StsblSchoolCertificateManagerConnectorBundle:Security:login.html.twig")
     */
    public function loginAction(Request $request)
    {
        if ($this->get('stsbl.scmc.security.scmcauth')->isAuthenticated()) {
            // go to index
            return $this->redirect($this->generateUrl('manage_scmc_index'));
        }

        $loginNotice = $this->get('session')->has('scmc_login_notice') ? $this->get('session')->get('scmc_login_notice') : null;
        $this->get('session')->remove('scmc_login_notice');
        
        $error = '';
        $form = $this->getLoginForm();
        $form->handleRequest($request);
        
        if ($form->isSubmitted()) {
            if (!$form->isValid()) {
                $this->handleFormErrors($form);
                goto render;
            }

            $data = $form->getData();
            $this->initalizeLogger();

            $ret = $this->get('stsbl.scmc.security.scmcauth')->login($data['masterpassword'], $data['userpassword']);

            if ($ret === 'code required') {
                // session requires 2fa code
                return $this->redirectToRoute('manage_scmc_code');
            } else if ($ret === true || empty($ret)) {
                // nop
            } else if ($ret === 'master password wrong') {
                $this->log('Zeugnisverwaltungs-Login: Falsches Masterpasswort');
                $error = _('The master password is wrong.');
                goto render;
            } else if ($ret === sprintf('user password for %s wrong', $this->getUser()->getUsername())) {
                $this->log('Zeugnisverwaltungs-Login: Falsches Benuterpasswort');
                $error = _('The user password is wrong.');
                goto render;
            } else {
                $this->log('Zeugnisverwaltungs-Login: Allgemeiner Fehler');
                $error = __('Something went wrong: %s', $ret);
                goto render;
            }
            
            $this->log('Zeugnisverwaltungs-Login erfolgreich');
            $this->get('iserv.flash')->success(_('You have logged in successfully in the Certificate Management Section.'));
            
            // assume successful login
            // check if previous url was provided
            $session = $this->get('session');
            if ($session->has('scmc_login_redirect') && $session->get('scmc_login_redirect') !== null) {
                $url = $session->get('scmc_login_redirect');
                $session->set('scmc_login_redirect', null);
            } else {
                $url = $this->generateUrl('manage_scmc_index');
            }

            return $this->redirect($url);
        }
        
        render:

        $act = $this->get('iserv.security_handler')->getToken()->getUser()->getUsername();
        /* @var $qb \Doctrine\ORM\QueryBuilder */
        $doctrine = $this->getDoctrine();
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $doctrine->getManager();
        /* @var $object \Stsbl\SchoolCertificateManagerConnectorBundle\Entity\UserPassword */
        $object = $em->find('StsblSchoolCertificateManagerConnectorBundle:UserPassword', $act);
        
        if ($object != null) {
            $hasUserPassword = $object->getPassword();
        } else {
            $hasUserPassword = false;
        }
            
        // parameters
        $view = $form->createView();
        $emptyMasterPassword = $this->get('stsbl.scmc.service.scmcadm')->masterPasswdEmpty();
        
        // track path
        $this->addBreadcrumb(_('Certificate Management'), $this->generateUrl('manage_scmc_forward'));
        
        return [
            'login_form' => $view,
            'emptyMasterPassword' => $emptyMasterPassword,
            'hasUserPassword' => $hasUserPassword,
            'error' => $error,
            'loginNotice' => $loginNotice,
            'help' => 'https://it.stsbl.de/documentation/mods/stsbl-iserv-scmc'
        ];
    }
    
    /**
     * Logouts user from current session
     * 
     * @param Request $request
     * @return RedirectResponse
     *
     * @Route("/logout", name="manage_scmc_logout")
     * @Security("token.hasAttribute('scmc_authenticated') and token.getAttribute('scmc_authenticated') == true")
     */
    public function logoutAction(Request $request)
    {
        if (!$this->get('stsbl.scmc.security.scmcauth')->close($this->getUser()->getUsername())) {
            throw new \RuntimeException('scmc_sess_close failed!');
        }
            
        $this->initalizeLogger();
        $this->log('Zeugnisverwaltungs-Logout erfolgreich');
        $this->get('iserv.flash')->success(_('You have logged out successfully from the Certificate Management Section.'));
    
        return $this->redirect($this->generateUrl('manage_scmc_forward'));
    }

    /**
     * Ask user for his OATH code
     *
     * @param Request $request
     * @return array
     *
     * @Route("/code", name="manage_scmc_code")
     * @Template()
     */
    public function codeAction(Request $request)
    {
        $form = $this->createCodeForm();
        $form->handleRequest($request);

        return [
            'form' => $form->createView(),
            'error' => null
        ];
    }

    /**
     * Creates form to enter 2fa code
     */
    private function createCodeForm()
    {
        $builder = $this->get('form.factory')->createNamedBuilder('code');

        $builder
            ->add('code', NumberType::class, [
                'label' => false,
                'constraints' => [new NotBlank(['message' => _('Please enter authorization code.')])],
                'attr' => [
                    'placeholder' => _('Authorization code'),
                    'autofocuse' => 'autofocus'
                ]
            ])
            ->add('actions', FormActionsType::class)
        ;

        $builder->get('actions')
            ->add('continue', SubmitType::class, array(
                    'label' => _('Finish login'),
                    'buttonClass' => 'btn-success',
                    'icon' => 'ok'
                )
            )
            ->add('cancel', SubmitType::class, array(
                'label' => _('Logout'),
                'buttonClass' => 'btn-danger',
                'icon' => 'log-out'
            ))
        ;

        return $builder->getForm();
    }
    
    /**
     * Creates form to login with masterpassword
     * 
     * @return \Symfony\Component\Form\FormInterface
     */
    private function getLoginForm()
    {
        $builder = $this->createFormBuilder();
        
        $builder
            ->add('masterpassword', PasswordType::class, array(
                'label' => false,
                'required' => true,
                'constraints' => new NotBlank(['message' => _('Please enter the master password and try it again.')]),
                'attr' => array(
                    'placeholder' => _('Master password'),
                    'autofocus' => 'autofocus'
                    )
                )
            )
            ->add('userpassword', PasswordType::class, array(
                'label' => false,
                'required' => true,
                'constraints' => new NotBlank(['message' => _('Please enter the user password and try it again.')]),
                'attr' => array(
                    'placeholder' => _('User password'),
                    'autofocus' => 'autofocus'
                    )
                )
            )
            ->add('submit', SubmitType::class, array(
                'label' => _('Login'),
                'buttonClass' => 'btn-primary'  
                )
            )
        ;
        
        return $builder->getForm();
    }
}