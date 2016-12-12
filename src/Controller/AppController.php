<?php
namespace App\Controller;

use App\Event\Badges;
use App\Event\Logs;
use App\I18n\Language;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\I18n\Time;

class AppController extends Controller
{

    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize()
    {
        parent::initialize();

        //Components.
        $this->loadComponent('Flash');
        $this->loadComponent('Cookie');
        $this->loadComponent('Acl.Acl');
        $this->loadComponent('Maintenance');
        $this->loadComponent('SessionsActivity');
        $this->loadComponent('Auth', [
            'className' => 'AclAuth',
            'allowedActionsForBanned' => [
                'Pages' => [
                    'home'
                ]
            ],
            'authenticate' => [
                'Form',
                'Xety/Cake3CookieAuth.Cookie'
            ],
            'flash' => [
                'element' => 'error',
                'key' => 'flash',
                'params' => [
                    'class' => 'error'
                ]
            ],
            'authorize' => [
                'Acl.Actions' => [
                    'actionPath' => 'app/'
                ]
            ],
            'loginAction' => [
                'controller' => 'users',
                'action' => 'login',
                'prefix' => false
            ],
            'unauthorizedRedirect' => [
                'controller' => 'pages',
                'action' => 'home',
                'prefix' => false
            ],
            'loginRedirect' => [
                'controller' => 'pages',
                'action' => 'home'
            ],
            'logoutRedirect' => [
                'controller' => 'pages',
                'action' => 'home'
            ]
        ]);

        $this->loadComponent('Csrf', [
            'secure' => env('HTTPS') ? true : false
        ]);
    }

    /**
     * beforeFilter handle.
     *
     * @param Event $event The beforeFilter event that was fired.
     *
     * @return void
     */
    public function beforeFilter(Event $event)
    {
        $this->loadModel('Settings');
        $this->Settings->setSettings();

        $this->Auth->config('authError', __('You need to be logged in or you are not authorized to access that location !'));

        // Define the language
        $language = new Language($this);
        $language->setLanguage();

        // Set trustProxy to get the original visitor IP
        $this->request->trustProxy = true;

        // Automatically Login
        if (!$this->Auth->user() && $this->Cookie->read('CookieAuth')) {
            $this->loadModel('Users');

            $userLogin = $this->Auth->identify();
            if ($userLogin && $userLogin['is_deleted'] == false) {
                $this->loadComponent('TwoFactorAuth');

                //Verify if the user use 2FA and if yes, if he's authorized.
                if ($userLogin['two_factor_auth_enabled'] == true && $this->TwoFactorAuth->isAuthorized($userLogin['id']) === false) {
                    $this->Cookie->delete('CookieAuth');
                } else {
                    $this->Auth->setUser($userLogin);

                    $user = $this->Users->newEntity($userLogin, ['accessibleFields' => ['id' => true]]);
                    $user->isNew(false);
                    $user->id = $userLogin['id'];

                    $user->last_login = new Time();
                    $user->last_login_ip = $this->request->clientIp();

                    $this->Users->save($user);

                    //Badges Event.
                    $this->eventManager()->attach(new Badges($this));
                    $badge = new Event('Model.Users.register', $this, [
                        'user' => $user
                    ]);
                    $this->eventManager()->dispatch($badge);

                    //Logs Event.
                    $this->eventManager()->attach(new Logs());
                    $event = new Event('Log.User', $this, [
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'user_ip' => $this->request->clientIp(),
                        'user_agent' => $this->request->header('User-Agent'),
                        'action' => 'user.connection.auto'
                    ]);
                    $this->eventManager()->dispatch($event);
                }
            } else {
                $this->Cookie->delete('CookieAuth');
            }
        }

        // Layouts
        if (isset($this->request->params['prefix'])) {
            $prefix = explode('/', $this->request->params['prefix'])[0];

            switch ($prefix) {
                case 'admin':
                    $this->viewBuilder()->layout('admin');
                    break;
            }
        }

        // Cookies
        $allowCookies = $this->Cookie->check('allowCookies');
        $this->set(compact('allowCookies'));

        // Maintenance
        $this->Maintenance->handle();

        // JavaScript Notifications
        if ($this->request->session()->read('Notification') && !empty($this->request->session()->read('Notification'))) {
            $notification = $this->request->session()->read('Notification');
            $this->request->session()->delete('Notification');

            $this->set(compact('notification'));
        }
    }

    /**
     * beforeRender hook method.
     *
     * @param Event $event The beforeRender event that was fired.
     *
     * @return void
     */
    public function beforeRender(Event $event)
    {
        parent::beforeRender($event);

        if ($this->components()->has('Auth')) {
            $builder = $this->viewBuilder();
            $builder->helpers([
                'Acl' => $this->Auth->config('authorize')['Acl.Actions']
            ]);
        }
    }
}
