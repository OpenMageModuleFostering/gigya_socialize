<?php
if (defined('COMPILER_INCLUDE_PATH')) {
    include_once 'Gigya_Social_sdk_GSSDK.php';
} else {
    include_once 'Gigya/Social/sdk/GSSDK.php';
}
require_once('Mage/Customer/controllers/AccountController.php');

/**
 * Class Gigya_Social_IndexController
 * @author
 */
class Gigya_Social_LoginController extends Mage_Customer_AccountController
{
    private $helper;
    private $userMode;
    private $gigyaData;

    public function indexAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * Action predispatch
     *
     * Check customer authentication for some actions
     */
    public function preDispatch()
    {
        // a brute-force protection here would be nice

        parent::preDispatch();

        if (!$this->getRequest()->isDispatched()) {
            return;
        }

        $action = $this->getRequest()->getActionName();
        $openActions = array(
            'create',
            'login',
            'logout',
            'loginPost',
            'logoutsuccess',
            'forgotpassword',
            'forgotpasswordpost',
            'resetpassword',
            'resetpasswordpost',
            'confirm',
            'confirmation'
        );
        $pattern = '/^(' . implode('|', $openActions) . ')/i';

        if (!preg_match($pattern, $action)) {
            if (!$this->_getSession()->authenticate($this)) {
                $this->setFlag('', 'no-dispatch', true);
            }
        } else {
            $this->_getSession()->setNoReferer(true);
        }
    }

    public function loginAction()
    {
        $this->helper = Mage::helper("Gigya_Social");
        $this->userMode = Mage::getStoreConfig('gigya_login/gigya_user_management/login_modes');
        $session = $this->_getSession();
        $req = $this->getRequest()->getPost('json');
        $post = json_decode($req, TRUE);
        $this->getResponse()->setHeader('Content-type', 'application/json');
        if ($this->userMode === 'social') {
            $this->gigyaData = $post;
            $this->_socialLogin($session, $post);
        } elseif ($this->userMode === 'raas') {
            $this->_raasLogin($session, $post);
        } else {
            $this->_getSession()->addError($this->__('Gigya login is disabled'));
        }
    }

    protected function _raasLogin($session, $post)
    {
        $valid = false;
        if (!empty($post) && isset($post['UIDSignature'])) {
            $secret = Mage::getStoreConfig('gigya_global/gigya_global_conf/secretkey');
            $valid = SigUtils::validateUserSignature($post['UID'], $post['signatureTimestamp'], $secret, $post['UIDSignature']);
        }
        if ($valid) {
            $accountInfo = $this->helper->utils->getAccount($post['UID']);
            if (is_numeric($accountInfo)) {
                $res = array(
                    'result' => 'message',
                    'message' => "Oops! Something went wrong during your login/registration process. Please try to login/register again."
                );
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            }
            $email = reset($accountInfo['loginIDs']['emails']);
            $this->gigyaData = $accountInfo;
            $cust_session = Mage::getSingleton('customer/session');
            // loginIDs is empty so this is the "secondary" user in Gigya
            if (empty($email)) {
                // delete user in gigya etc...
                $this->_disableGigyaSeconderyAccount($post['UID'], $accountInfo);
                return;
            }
            $cust = $this->_customerExists($email);
            // customer email exists login flow
            if ($cust != false) {
                Mage::dispatchEvent('gigya_raas_pre_login', array(
                   'customer' => $cust,
                   'gigyaData' => $this->gigyaData
                ));
                $cust->firstname = $accountInfo['profile']['firstName'];
                $cust->lastname = $accountInfo['profile']['lastName'];
                $cust->save();
                $cust_session->setCustomerAsLoggedIn($cust);
                Mage::dispatchEvent('gigya_raas_post_login', array(
                        'customer_session' => $cust_session,
                        'gigyaData' => $this->gigyaData
                    ));
                $url = Mage::getUrl('*/*/*', array('_current' => true));
                $cust_session->setData('gigyaAccount', $accountInfo);
                $res = array(
                    'result' => 'login',
                    'redirect' => $url
                );
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            } else {
                // create a user in magento
                $firstName = $accountInfo['profile']['firstName'];
                $lastName = $accountInfo['profile']['lastName'];
                $this->_createCustomer($email, $firstName, $lastName, $accountInfo);
            }
        }
    }

    private function _cutomerExist($email) {
        $customer = Mage::getModel('customer/customer');
        $websiteId = Mage::app()->getWebsite()->getId();
        if ($websiteId) {
            $customer->setWebsiteId($websiteId);
        }
        $customer->loadByEmail($email);
        if ($customer->getId()) {
            return $customer;
        } else {
            return false;
        }
    }

    private function _disableGigyaSeconderyAccount($uid, $account) {
        Mage::helper('Gigya_Social')->utils->disableAccountByGUID($uid);
        $providers = Mage::helper('Gigya_Social')->utils->getProviders($account);
        $msg = sprintf($this->__( 'We found your email in our system.<br>Please login to your existing account using your <strong>%1$s</strong> identity.' ), $providers['primary'], $providers['secondary'] );
        $res = array(
            'result' => 'message',
            'message' => $msg
        );
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
    }

    protected function  _socialLogin($session, $post)
    {
        if (!empty($post) && isset($post['signature'])) {
            $secret = Mage::getStoreConfig('gigya_global/gigya_global_conf/secretkey');
            $valid = SigUtils::validateUserSignature($post['UID'], $post['timestamp'], $secret, $post['signature']);
            $firstName = $post['user']['firstName'];
            $lastName = $post['user']['lastName'];
            $email = $post['user']['email'];
            if ($valid == TRUE) {
                //see if user is a site user
                if ($post['isSiteUID'] && is_numeric($post['UID'])) {
                    $cust_session = Mage::getSingleton('customer/session');
                    $cust_session->setData('gigyaAction', 'login');
                    Mage::dispatchEvent('gigya_social_pre_login', array(
                            'customer_session' => $cust_session,
                            'gigyaData' => $this->gigyaData
                        ));
                    $cust_session->loginById($post['user']['UID']);
                    Mage::dispatchEvent('gigya_social_post_login', array(
                            'customer_session' => $cust_session,
                            'gigyaData' => $this->gigyaData
                        ));
                    $cust_session->setData('gigyaAccount', $post);
                    //$url = Mage::getUrl('customer/account');
                    $url = Mage::getUrl('*/*/*', array('_current' => true));
                    $res = array(
                        'result' => 'login',
                        'redirect' => $url
                    );
                    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                } else {
                    //no email
                    if (empty($post['user']['email'])) {
                        //return email form
                        $block = $this->getLayout()->createBlock(
                            'Mage_Core_Block_Template',
                            'Emailform',
                            array('template' => 'gigya/form/emailForm.phtml')
                        );
                        $form = $block->renderView();
                        $res = array(
                            'result' => 'noEmail',
                            'html' => $form,
                            'id' => Mage::helper('Gigya_Social')->getPluginContainerId('gigya_login/gigya_login_conf'),
                            'headline' => $this->__('Fill-in missing required info'),
                        );
                        $this->getResponse()->setHeader('Content-type', 'application/json');
                        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                    } else {
                        //check if we have the email on the system
                        $customer = $this->_customerExists($post['user']['email']);
                        if ($customer === FALSE) {
                            $this->_createCustomer($email, $firstName, $lastName, $post['user']);
                            $this->getResponse()->setHeader('Content-type', 'application/json');
                        } else {
                            //email exsites
                            try {
                                //return login form
                                $block = $this->getLayout()->createBlock(
                                    'Mage_Core_Block_Template',
                                    'Loginform',
                                    array('template' => 'gigya/form/mini.login.phtml')
                                );
                                $form = $block->renderView();
                                $res = array(
                                    'result' => 'emailExsists',
                                    'html' => $form,
                                    'id' => Mage::helper('Gigya_Social')->getPluginContainerId('gigya_login/gigya_login_conf'),
                                    'headline' => $this->__('Link Accounts'),
                                );
                                Mage::getSingleton('customer/session')->setData('gigyaAction', 'linkAccount');
                                Mage::getSingleton('customer/session')->setData('gigyaUid', $post['UID']);
                                $this->getResponse()->setHeader('Content-type', 'application/json');
                                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                            } catch (Exception $e) {
                                //TODO:add error handeling
                                Mage::log($e);
                            }
                        }
                    }
                }
            } else {
                //not valid
                Mage::log('Not Valid');
            }
        }
    }

    protected function _customerExists($email, $websiteId = null)
    {
        $customer = Mage::getModel('customer/customer');
        if ($websiteId) {
            $customer->setWebsiteId($websiteId);
        } else {
            $customer->setWebsiteId(Mage::app()->getWebsite()->getId());
        }
        $customer->loadByEmail($email);
        if ($customer->getId()) {
            return $customer;
        }
        return FALSE;
    }

    protected function _createCustomer($email, $firstName = NULL, $lastName = NULL, $gigyaUser)
    {
        $customer = Mage::getModel('customer/customer')->setId(null);
        $customer->getGroupId();
        $customer->setFirstname($firstName);
        $customer->setLastname($lastName);
        $customer->setEmail($email);
        if (!empty($gigyaUser['missInfo'])) {
            $missing_info = $gigyaUser['missInfo'];
            if (array_key_exists('dob', $missing_info)) {
                $this->buildDob($missing_info);
            }
            foreach ($missing_info as $key => $val) {
                $k = 'set' . ucfirst($key);
                $customer->{$k}($val);
            }
        }
        $password = Mage::helper('Gigya_Social')->_getPassword();
        $_POST['password'] = $password;
        $_POST['confirmation'] = $password;
        if ($this->userMode == 'social') {
            $customer->setData('gigyaUser', $gigyaUser);
        } else if ($this->userMode == 'raas') {
            $cust_session = Mage::getSingleton('customer/session');
            $cust_session->setData('gigyaAccount', $gigyaUser);
            $customer->setData('gigya_uid', $gigyaUser['UID']);
        }
        Mage::register('current_customer', $customer);
        $this->_forward('createPost', null, null, array('gigyaData' => $gigyaUser));
    }

    private function buildDob(&$info)
    {
        $info['dob'] = $info['year'] . "-" . $info['month'] . "-" . $info['day'];
        unset($info['year'], $info['month'], $info['day']);
    }

    public function createPostAction()
    {
        $session = $this->_getSession();
        if ($session->isLoggedIn()) {
            Mage::log('loggedIn');
            return;
        }
        $session->setEscapeMessages(true); // prevent XSS injection in user input
        if ($this->getRequest()->isPost()) {
            $errors = array();

            if (!$customer = Mage::registry('current_customer')) {
                $customer = Mage::getModel('customer/customer')->setId(null);
            }

            /* @var $customerForm Mage_Customer_Model_Form */
            $customerForm = Mage::getModel('customer/form');
            $customerForm->setFormCode('customer_account_create')
                ->setEntity($customer);

            $customerData = $customerForm->extractData($this->getRequest());

            if ($this->getRequest()->getParam('is_subscribed', false)) {
                $customer->setIsSubscribed(1);
            }

            /**
             * Initialize customer group id
             */
            $customer->getGroupId();

            if ($this->getRequest()->getPost('create_address')) {
                /* @var $address Mage_Customer_Model_Address */
                $address = Mage::getModel('customer/address');
                /* @var $addressForm Mage_Customer_Model_Form */
                $addressForm = Mage::getModel('customer/form');
                $addressForm->setFormCode('customer_register_address')
                    ->setEntity($address);

                $addressData = $addressForm->extractData($this->getRequest(), 'address', false);
                $addressErrors = $addressForm->validateData($addressData);
                if ($addressErrors === true) {
                    $address->setId(null)
                        ->setIsDefaultBilling($this->getRequest()->getParam('default_billing', false))
                        ->setIsDefaultShipping($this->getRequest()->getParam('default_shipping', false));
                    $addressForm->compactData($addressData);
                    $customer->addAddress($address);

                    $addressErrors = $address->validate();
                    if (is_array($addressErrors)) {
                        $errors = array_merge($errors, $addressErrors);
                    }
                } else {
                    $errors = array_merge($errors, $addressErrors);
                }
            }

            try {
                $customerErrors = $customerForm->validateData($customerData);
                if ($customerErrors !== true) {
                    $fields = $customerForm->getAttributes();
                    foreach ($fields as $field) {
                        $requireds[$field->getAttributeCode()] = $field->getIsRequired();
                    }
                    //remove fields that we have data for
                    unset($requireds['firstname'], $requireds['lastname'], $requireds['email']);
                    $requireds = array_filter($requireds);
                    $html = '<div class="gigyaMoreInfo"><form action="' . Mage::getBaseUrl() . 'gigyalogin/login" name="moreInfo" id="gigyaMoreInfoForm">';
                    foreach ($requireds as $key => $r) {
                        $requireds[$key] = $fields[$key]->getStoreLabel();
                        if (!$fields[$key]->getIsUserDefined() && is_object($this->getLayout()->createBlock('customer/widget_' . $key))) {
                            $html .= $this->getLayout()->createBlock('customer/widget_' . $key)->toHtml();
                        } else {
                            $html .= '<div class="field">
                        <label for="' . $key . '">' . $fields[$key]->getStoreLabel() . '</label>
                        <div class="input-box">
                            <input type="text" name="' . $key . '" id="' . $key . '" value="" class="input-text" />
                        </div>
                    </div>';
                        }
                    }
                    $html .= '<input class="button" id="gigyaMoreInfoSubmit" type="button" value="Send" onclick="gigyaFunctions.moreInfoSubmit()" "></form>';
                    $html .= '</div>';

                    $res = array(
                        'result' => 'moreInfo',
                        'fields' => $requireds,
                        'html' => $html,
                    );
                    $this->getResponse()->setHeader('Content-type', 'application/json');
                    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                    return;
                } else {
                    $customerForm->compactData($customerData);
                    $customer->setPassword($this->getRequest()->getPost('password'));
                    $customer->setConfirmation($this->getRequest()->getPost('confirmation'));
                    $customerErrors = $customer->validate();
                    if (is_array($customerErrors)) {
                        $errors = array_merge($customerErrors, $errors);
                    }
                }

                $validationResult = count($errors) == 0;

                if (true === $validationResult) {
                    $params = $this->getRequest()->getParams();
                    Mage::dispatchEvent('gigya_pre_user_create', array(
                            'customer' => $customer,
                            'gigya_data' => $params['gigyaData']
                        ));
                    $customer->save();
                    Mage::dispatchEvent('customer_register_success',
                        array('account_controller' => $this, 'customer' => $customer)
                    );

                    if ($customer->isConfirmationRequired()) {
                        $customer->sendNewAccountEmail(
                            'confirmation',
                            $session->getBeforeAuthUrl(),
                            Mage::app()->getStore()->getId()
                        );
                        $session->addSuccess($this->__('Account confirmation is required. Please, check your email for the confirmation link. To resend the confirmation email please <a href="%s">click here</a>.', Mage::helper('customer')->getEmailConfirmationUrl($customer->getEmail())));
                        return;
                    } else {
                        $session->setCustomerAsLoggedIn($customer);
                        $url = $this->_welcomeCustomer($customer);
                        //$url = Mage::getUrl('customer/account');
                        //$this->_redirectSuccess($url);
                        $res = array(
                            'result' => 'newUser',
                            'redirect' => $url
                        );
                        $this->getResponse()->setHeader('Content-type', 'application/json');
                        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                    }
                } else {
                    Mage::log($errors);
                    $session->setCustomerFormData($this->getRequest()->getPost());
                    $error = '';
                    if (is_array($errors)) {
                        foreach ($errors as $errorMessage) {
                            $session->addError($errorMessage);
                            $error .= $errorMessage . "\n";
                        }
                        $res['result'] = 'error';
                        $res['message'] = $error;
                    } else {
                        $res['result'] = 'error';
                        $res['message'] = $this->__('Invalid customer data');
                    }
                }
                $this->getResponse()->setHeader('Content-type', 'application/json');
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            } catch (Mage_Core_Exception $e) {
                $session->setCustomerFormData($this->getRequest()->getPost());
                if ($e->getCode() === Mage_Customer_Model_Customer::EXCEPTION_EMAIL_EXISTS) {
                    $message = $this->__('There is already an account with this email address.');
                } else {
                    $message = $e->getMessage();
                }
                $res['result'] = 'error';
                $res['message'] = $message;
                $this->getResponse()->setHeader('Content-type', 'application/json');
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            } catch (Exception $e) {
                $session->setCustomerFormData($this->getRequest()->getPost())
                    ->addException($e, $this->__('Cannot save the customer.'));
                $message = $this->__('Cannot save the customer.');
                $res['result'] = 'error';
                $res['message'] = $message;
                $this->getResponse()->setHeader('Content-type', 'application/json');
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            }
        }

        Mage::log('error');
        //$this->_redirectError(Mage::getUrl('*/*/create', array('_secure' => true)));
    }

    /**
     * Login post action
     */
    public function loginPostAction()
    {
        if ($this->_getSession()->isLoggedIn()) {
            $res['result'] = 'error';
            $res['message'] = $this->__('User is logged in.');
            $this->getResponse()->setHeader('Content-type', 'application/json');
            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            return;
        }
        $session = $this->_getSession();

        if ($this->getRequest()->isPost()) {
            $login = Mage::helper('core')->jsonDecode($this->getRequest()->getPost('login'));
            if (!empty($login['username']) && !empty($login['password'])) {
                $res = array();
                try {
                    $session->login($login['username'], $login['password']);
                    if ($session->getCustomer()->getIsJustConfirmed()) {
                        $this->_welcomeCustomer($session->getCustomer(), true);
                    }
                    $res['result'] = 'success';
                    $this->getResponse()->setHeader('Content-type', 'application/json');
                    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                } catch (Mage_Core_Exception $e) {
                    switch ($e->getCode()) {
                        case Mage_Customer_Model_Customer::EXCEPTION_EMAIL_NOT_CONFIRMED:
                            $value = Mage::helper('customer')->getEmailConfirmationUrl($login['username']);
                            $message = Mage::helper('customer')->__('This account is not confirmed. <a href="%s">Click here</a> to resend confirmation email.', $value);
                            break;
                        case Mage_Customer_Model_Customer::EXCEPTION_INVALID_EMAIL_OR_PASSWORD:
                            $message = $e->getMessage();
                            break;
                        default:
                            $message = $e->getMessage();
                    }
                    $res['result'] = 'error';
                    $res['message'] = $message;
                    $this->getResponse()->setHeader('Content-type', 'application/json');
                    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
                } catch (Exception $e) {
                    // Mage::logException($e); // PA DSS violation: this exception log can disclose customer password
                }
            } else {
                $res['result'] = 'error';
                $res['message'] = $this->__('Login and password are required.');
                $this->getResponse()->setHeader('Content-type', 'application/json');
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
            }
        }
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
    }

    public function logoutAction()
    {
        $cust = $this->_getSession()->logout()->setBeforeAuthUrl(null);
        if ($cust->getId() === null) {
            $res['result'] = 'success';
        } else {
            $res['result'] = 'error';
        }

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($res));
    }

    protected function _isSiteUser($info)
    {
        return null;
    }

}

