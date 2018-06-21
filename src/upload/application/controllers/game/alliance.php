<?php
/**
 * Alliance Controller
 *
 * PHP Version 5.5+
 *
 * @category Controller
 * @package  Application
 * @author   XG Proyect Team
 * @license  http://www.xgproyect.org XG Proyect
 * @link     http://www.xgproyect.org
 * @version  3.0.0
 */
namespace application\controllers\game;

use application\core\Controller;
use application\core\enumerators\AllianceRanksEnumerator as AllianceRanks;
use application\core\enumerators\SwitchIntEnumerator as SwitchInt;
use application\libraries\alliance\Alliances;
use application\libraries\FormatLib;
use application\libraries\FunctionsLib;
use application\libraries\Timing_library;

use const DPATH;
use const JS_PATH;

/**
 * Alliance Class
 *
 * @category Classes
 * @package  Application
 * @author   XG Proyect Team
 * @license  http://www.xgproyect.org XG Proyect
 * @link     http://www.xgproyect.org
 * @version  3.1.0
 */
class Alliance extends Controller
{

    const MODULE_ID = 13;

    /**
     *
     * @var \BBCodeLib 
     */
    private $_bbcode = null;
    
    /**
     *
     * @var type \Users_library
     */
    private $_user;

    /**
     *
     * @var \Alliance
     */
    private $_alliance = null;

    /**
     * Constructor
     * 
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // check if session is active
        parent::$users->checkSession();

        // load Model
        parent::loadModel('game/alliance');

        // load Library
        $this->_bbcode = FunctionsLib::loadLibrary('BBCodeLib');
        
        // Check module access
        FunctionsLib::moduleMessage(FunctionsLib::isModuleAccesible(self::MODULE_ID));

        // set data
        $this->_user = $this->getUserData();

        // init a new buddy object
        $this->setUpAlliances();

        // time to do something
        $this->runAction();

        // build the page
        $this->buildPage();
    }

    /**
     * Creates a new alliance object that will handle all the alliance
     * creation methods and actions
     * 
     * @return void
     */
    private function setUpAlliances()
    {
        $this->_alliance = new Alliances(
            $this->Alliance_Model->getAllianceDataById($this->getAllianceId()),
            $this->_user['user_id'],
            $this->_user['user_ally_rank_id']
        );
    }

    /**
     * Check if the page that the user is trying to access is allowed
     * 
     * @return boolean
     */
    private function isPageAllowed()
    {
        $allowed_pages = [
            'public' => [
                'default', 'ainfo', 'make', 'search', 'apply'
            ],
            'awaitingApproval' => [
                'default', 'ainfo'
            ],
            'isMember' => [
                'default', 'ainfo', 'exit', 'memberslist', 'circular', 'admin'
            ]
        ];

        return in_array(
            $this->getCurrentSection(), array_filter($allowed_pages[$this->getUserAccess()], function($value) {
                return $value;
            })
        );
    }

    /**
     * Get user level based on their alliance status
     * 
     * @return string
     */
    private function getUserAccess()
    {
        // not in an alliance
        if ((int) $this->_user['user_ally_id'] === 0) {

            // doesn't have a request
            if ((int) $this->_user['user_ally_request'] === 0) {

                // it's public then
                return 'public';
            }

            // supposedly a request was sent
            return 'awaitingApproval';
        }

        // any other case
        return 'isMember';
    }

    /**
     * Get current alliance ID
     * 
     * @return int
     */
    private function getAllianceId()
    {
        $alliance_id = filter_input(INPUT_GET, 'allyid', FILTER_VALIDATE_INT);

        if (!empty($alliance_id) && (int) $alliance_id != 0) {
            
            return $alliance_id;
        }
        
        if ($this->_user['user_ally_id'] != 0) {
            
            return $this->_user['user_ally_id'];
        }
        
        if ($this->_user['user_ally_request'] != 0) {
            
            return $this->_user['user_ally_request'];
        }
    }

    /**
     * Determine the current page and validate it
     * 
     * @return string
     */
    private function getCurrentSection()
    {
        $mode = filter_input(INPUT_GET, 'mode');

        return (isset($mode) ? $mode : 'default');
    }

    /**
     * Run an action
     * 
     * @return void
     */
    private function runAction()
    {
        
    }

    /**
     * Build the page
     * 
     * @return void
     */
    private function buildPage()
    {
        if (!$this->isPageAllowed()) {

            FunctionsLib::redirect('game.php?page=alliance');
        }

        parent::$page->display(
            $this->{'get' . ucfirst($this->getCurrentSection()) . 'Section'}()
        );
    }

    
    
    /**
     * 
     * PUBLIC / MEMBERS SECTIONS
     * 
     */
    
    
    
    /**
     * Get alliance default section, calling the right method
     * 
     * @return string
     */
    private function getDefaultSection()
    {
        return $this->{'getDefault' . ucfirst($this->getUserAccess()) . 'Section'}();
    }

    /**
     * Get alliance default menu for search and create
     * 
     * @return type
     */
    private function getDefaultPublicSection()
    {
        return $this->getTemplate()->set('alliance/alliance_default_menu_view', $this->getLang());
    }

    /**
     * Get alliance default message for awaiting approval
     * 
     * @return string
     */
    private function getDefaultAwaitingApprovalSection()
    {
        $cancel = filter_input(INPUT_POST, 'bcancel');
        $request_text = $this->getLang()['al_request_wait_message']; 
        $button_text = $this->getLang()['al_delete_request'];
        
        if (!empty($cancel)) {
            
            $this->Alliance_Model->cancelUserRequestById($this->_user['user_id']);
            $request_text = $this->getLang()['al_request_deleted'];
            $button_text = $this->getLang()['al_continue'];
        }
        
        return $this->getTemplate()->set(
            'alliance/alliance_awaiting_view', 
            array_merge(
                [
                    'request_text' => str_replace('%s', $this->_alliance->getCurrentAlliance()->getAllianceTag(), $request_text),
                    'button_text' => $button_text
                ], 
                $this->getLang()
            )
        );
    }

    /**
     * Get default section for a member user
     * 
     * @return string
     */
    private function getDefaultIsMemberSection()
    {
        $blocks = [
            'tag', 'name', 'members', 'rank', 'requests', 'circular', 'web'
        ];
        $details = [];
        
        foreach ($blocks as $block) {
            
            $data = $this->{'build' . ucfirst($block) . 'Block'}();
            
            if (empty($data['detail_content'])) {
                
                continue;
            }
            
            $details[] = $data;
        }
        
        return $this->getTemplate()->set(
            'alliance/alliance_front_page_view', array_merge([
                'image' => $this->buildImageBlock(),
                'details' => $details,
                'description' => $this->buildDescriptionBlock(),
                'text' => $this->buildTextBlock(),
                'leave' => $this->buildLeaveBlock()
            ], $this->getLang())
        );
    }

    /**
     * Get alliance information section
     * 
     * @return string
     */
    private function getAinfoSection()
    {
        return $this->getTemplate()->set(
            'alliance/alliance_ainfo', 
            array_merge(
                $this->getLang(), 
                [
                    'image' => $this->buildImageBlock(),
                    'tag' => $this->_alliance->getCurrentAlliance()->getAllianceTag(),
                    'name' => $this->_alliance->getCurrentAlliance()->getAllianceName(),
                    'members' => $this->_alliance->getCurrentAlliance()->getAllianceMembers(),
                    'description' => $this->buildDescriptionBlock(),
                    'web' => $this->buildWebBlock()['detail_content'],
                    'requests' => $this->buildPublicRequestsBlock()
                ]
            )
        );
    }

    /**
     * Get alliance search section
     * 
     * @return string
     */
    private function getSearchSection()
    {
        $search_string = filter_input(INPUT_POST, 'searchtext');
        $search_page = $this->getTemplate()->set(
            'alliance/alliance_search_form_view',
            array_merge(['searchtext' => $search_string], $this->getLang())
        );

        if (!empty($search_string)) {

            $list_of_results = [];
            $results = new Alliances(
                $this->Alliance_Model->searchAllianceByNameTag($search_string), $this->_user['user_id']
            );

            foreach ($results->getAlliance() as $result) {

                $list_of_results[] = [
                    'ally_tag' => FunctionsLib::setUrl('game.php?page=alliance&mode=apply&allyid=' . $result->getAllianceId(), '', $result->getAllianceTag()),
                    'alliance_name' => $result->getAllianceName(),
                    'ally_members' => $result->getAllianceMembers()
                ];
            }

            $search_page .= $this->getTemplate()->set(
                'alliance/alliance_search_results_view',
                array_merge(['list_of_results' => $list_of_results], $this->getLang())
            );
        }

        return $search_page;
    }

    /**
     * Get alliance make section
     * 
     * @return string
     */
    private function getMakeSection()
    {
        $action = filter_input_array(INPUT_POST);
        
        if (is_array($action)) {
            
            $alliance_tag = $this->validateTag($action['atag']);
            $alliance_name = $this->validateName($action['aname']);

            $this->Alliance_Model->createNewAlliance(
                $alliance_name, $alliance_tag, $this->_user['user_id'], $this->getLang()['al_alliance_founder_rank']
            );

            $message = str_replace(['%s', '%d'], [$alliance_name, $alliance_tag], $this->getLang()['al_created']);
            return $this->messageBox(
                $message, 
                $message . "<br/><br/>", 
                'game.php?page=alliance', 
                $this->getLang()['al_continue']
            );
        } else {
         
            return $this->getTemplate()->set('alliance/alliance_make_view', $this->getLang());
        }
    }

    /**
     * Get alliance apply section
     * 
     * @return string
     */
    private function getApplySection()
    {
        $request = filter_input_array(INPUT_POST);
        
        if ($this->_alliance->getCurrentAlliance()->getAllianceRequestNotAllow()) {
            
            FunctionsLib::message($this->getLang()['al_alliance_closed'], 'game.php?page=alliance', 3);
        }
        
        if ($request['send'] != null && !empty($request['text'])) {
            
            $this->Alliance_Model->createNewUserRequest(
                $this->getAllianceId(), $request['text'], $this->_user['user_id']
            );

            FunctionsLib::message($this->getLang()['al_request_confirmation_message'], 'game.php?page=alliance', 3);
        }

        return $this->getTemplate()->set('alliance/alliance_apply_form_view', array_merge(
            $this->getLang(),
            [
                'js_path' => JS_PATH,
                'allyid' => $this->getAllianceId(),
                'text_apply' => (!empty($this->_alliance->getCurrentAlliance()->getAllianceRequest())) ? $this->_alliance->getCurrentAlliance()->getAllianceRequest() : $this->getLang()['al_default_request_text'],
                'write_to_alliance' => strtr(
                    $this->getLang()['al_write_request'],
                    ['%s' => $this->_alliance->getCurrentAlliance()->getAllianceTag()]
                )
            ]
        ));
    }

    /**
     * Get alliance member list section
     * 
     * @return string
     */
    private function getMemberslistSection()
    {
        if (!$this->_alliance->hasAccess(AllianceRanks::view_member_list)) {
            
            FunctionsLib::redirect('game.php?page=alliance');
        }
        
        $sort_by_field = filter_input(INPUT_GET, 'sort1');
        $sort_by_order = filter_input(INPUT_GET, 'sort2');
        $sort_by_order_rules = [1 => 2, 2 => 1];
        
        $members = $this->Alliance_Model->getAllianceMembers(
            $this->_user['user_ally_id'], $sort_by_field, $sort_by_order
        );
        
        $position = 0;
        $members_list = [];

        foreach($members as $member) {
            
            $position++;

            $members_list[] = [
                'position' => $position,
                'user_name' => $member['user_name'],
                'user_id' => $member['user_id'],
                'dpath' => DPATH,
                'write_message' => $this->getLang()['write_message'],
                'user_ally_range' => $this->getUserRank($member['user_id'], $member['user_ally_rank_id']),
                'points' => FormatLib::prettyNumber($member['user_statistic_total_points']),
                'user_galaxy' => $member['user_galaxy'],
                'user_system' => $member['user_system'],
                'coords' => FormatLib::prettyCoords($member['user_galaxy'], $member['user_system'], $member['user_planet']),
                'user_ally_register_time' => Timing_library::formatDefaultTime($member['user_ally_register_time']),
                'online_time' => $this->_alliance->hasAccess(AllianceRanks::online_status) ? Timing_library::setOnlineStatus($member['user_onlinetime']) : '-'
            ];
        }
        
        return $this->getTemplate()->set(
            'alliance/alliance_members_view',
            array_merge(
                $this->getLang(),
                [
                    'total' => $position,
                    's' => isset($sort_by_order_rules[$sort_by_order]) ? $sort_by_order_rules[$sort_by_order] : 1,
                    'list_of_members' => $members_list
                ]
            )
        );
    }

    /**
     * Get alliance circular section
     * 
     * @return string
     */
    private function getCircularSection()
    {
        if (!$this->_alliance->hasAccess(AllianceRanks::send_circular)) {
            
            FunctionsLib::redirect('game.php?page=alliance');
        }
        
        if ((bool) filter_input(INPUT_GET, 'sendmail', FILTER_VALIDATE_INT)) {

            $post = filter_input_array(INPUT_POST, [
                'r' => FILTER_SANITIZE_NUMBER_INT,
                'text' => FILTER_SANITIZE_STRING
            ]);

            $members_list = [];
            
            if (!(bool)$post['r']) {
                
                $members = $this->Alliance_Model->getAllianceMembersById(
                    $this->_user['user_ally_id']
                );
            } else {
                
                $members = $this->Alliance_Model->getAllianceMembersByIdAndRankId(
                    $this->_user['user_ally_id'], $post['r']
                );
            }

            if (count($members) > 0) {
                
                foreach ($members as $member) {
                    
                    FunctionsLib::sendMessage(
                        $member['user_id'],
                        $this->_user['user_id'],
                        '',
                        3,
                        $this->_alliance->getCurrentAlliance()->getAllianceTag(),
                        $this->_user['user_name'],
                        $post['text']
                    );
                    
                    $members_list[] = $member['user_name'];
                }
            }

            return $this->messageBox(
                $this->getLang()['al_circular_sended'],
                join("<br/>", $members_list),
                'game.php?page=alliance',
                $this->getLang()['al_continue'],
                true
            );
        }

        $ranks = $this->_alliance->getCurrentAllianceRankObject();
        $list_of_ranks = $ranks->getAllRanksAsArray();
            
        $ranks_list = [];
        
        if (is_array($list_of_ranks)) {

            foreach ($list_of_ranks as $id => $rank) {

                $ranks_list[] = [
                    'value' => $id + 1,
                    'name' => $rank['rank']
                ];
            }   
        }
        
        return $this->getTemplate()->set(
            'alliance/alliance_circular_view',
            array_merge(
                $this->getLang(),
                [
                    'js_path' => JS_PATH,
                    'ranks_list' => $ranks_list
                ]
            )
        );
    }

    /**
     * Get alliance exit section
     * 
     * @return string
     */
    private function getExitSection()
    {
        if ($this->_alliance->isOwner()) {
            
            FunctionsLib::message($this->getLang()['al_founder_cant_leave_alliance'], 'game.php?page=alliance', 3);
        }

        if ((bool) filter_input(INPUT_GET, 'yes', FILTER_VALIDATE_INT)) {
           
            $this->Alliance_Model->exitAlliance($this->_user['user_id']);
            
            return $this->messageBox(
                strtr($this->getLang()['al_leave_sucess'], ["%s" => $this->_alliance->getCurrentAlliance()->getAllianceName()]),
                '<br>',
                'game.php?page=alliance',
                $this->getLang()['al_continue']
            );
        }
        
        return $this->messageBox(
            strtr($this->getLang()['al_do_you_really_want_to_go_out'], ["%s" => $this->_alliance->getCurrentAlliance()->getAllianceName()]),
            '<br>',
            'game.php?page=alliance&mode=exit&yes=1',
            $this->getLang()['al_go_out_yes']
        );
    }

    
    
    /**
     * 
     * ADMINS SECTION
     * 
     */
    
    
    
    /**
     * Get alliance admin section
     * 
     * @return string
     */
    private function getAdminSection()
    {
        $edit = filter_input(INPUT_GET, 'edit');

        $admin_sections = [
            'ally' => '',
            'exit' => AllianceRanks::delete,
            'members' => AllianceRanks::administration,
            'name' => AllianceRanks::administration,
            'requests' => AllianceRanks::application_management,
            'rights' => AllianceRanks::right_hand,
            'tag' => AllianceRanks::administration,
            'transfer' => AllianceRanks::administration
        ];
        
        if (isset($admin_sections[$edit]) && $this->_alliance->hasAccess($admin_sections[$edit])) {
            
            return $this->{'getAdmin' . ucfirst($edit) . 'Section'}();
        }
        
        FunctionsLib::redirect('game.php?page=alliance');
    }

    /**
     * Get admin main section
     * 
     * @return string
     */
    private function getAdminAllySection()
    {
        $t = filter_input(INPUT_GET, 't', FILTER_VALIDATE_INT, [
            'options' => [
                'default' => 1,
                'min_range' => 1,
                'max_range' => 3
            ]
        ]);

        $post = filter_input_array(INPUT_POST, [
            't' => [
                'filter' => FILTER_SANITIZE_NUMBER_INT,
                'options' => ['default' => 1, 'min_range' => 1, 'max_range' => 3]
            ],
            'text' => FILTER_SANITIZE_STRING,
            'options' => FILTER_SANITIZE_STRING,
            'owner_range' => FILTER_SANITIZE_STRIPPED,
            'web' => FILTER_VALIDATE_URL,
            'image' => FILTER_VALIDATE_URL,
            'request_notallow' => [
                'filter' => FILTER_SANITIZE_NUMBER_INT,
                'options' => ['default' => 1, 'min_range' => 0, 'max_range' => 1]
            ]
        ]);

        if (isset($post['options'])) {

            $this->Alliance_Model->updateAllianceSettings(
                $this->getAllianceId(),
                [
                    'alliance_owner_range' => ($post['owner_range'] ? FunctionsLib::escapeString($post['owner_range']) : ''),
                    'alliance_web' => ($post['web'] ? FunctionsLib::escapeString($post['web']) : ''),
                    'alliance_image' => ($post['image'] ? FunctionsLib::escapeString($post['image']) : ''),
                    'alliance_request_notallow' => $post['request_notallow']
                ]
            );
            
            FunctionsLib::redirect('game.php?page=alliance&mode=admin&edit=ally');
        }
        
        if (isset($post['t'])) {

            $callback = [
                1 => 'Description',
                2 => 'Text',
                3 => 'RequestText'
            ];
            
            $this->Alliance_Model->{'updateAlliance' . $callback[$t]}(
                $this->getAllianceId(),
                FunctionsLib::formatText($post['text'])
            );
            
            FunctionsLib::redirect('game.php?page=alliance&mode=admin&edit=ally&t=' . $t);
        }
        
        $request_type = [
            1 => $this->getLang()['al_outside_text'],
            2 => $this->getLang()['al_inside_text'],
            3 => $this->getLang()['al_request_text']
        ];
        
        $text = [
            1 => $this->_alliance->getCurrentAlliance()->getAllianceDescription(),
            2 => $this->_alliance->getCurrentAlliance()->getAllianceText(),
            3 => $this->_alliance->getCurrentAlliance()->getAllianceRequest()
        ];
        
        return $this->getTemplate()->set(
            'alliance/alliance_admin',
            array_merge(
                $this->getLang(),
                [
                    'js_path' => JS_PATH,
                    'dpath' => DPATH,
                    't' => $t,
                    'request_type' => $request_type[$t],
                    'text' => $text[$t],
                    'alliance_web' => $this->_alliance->getCurrentAlliance()->getAllianceWeb(),
                    'alliance_image' => $this->_alliance->getCurrentAlliance()->getAllianceImage(),
                    'alliance_request_notallow_0' => $this->_alliance->getCurrentAlliance()->getAllianceRequestNotAllow() == SwitchInt::on ? 'selected' : '',
                    'alliance_request_notallow_1' => $this->_alliance->getCurrentAlliance()->getAllianceRequestNotAllow() == SwitchInt::off ? 'selected' : '',
                    'alliance_owner_range' => $this->_alliance->getCurrentAlliance()->getAllianceOwnerRange(),
                ]
            )
        );
    }
    
    /**
     * Get admin delete section
     * 
     * @return string
     */
    private function getAdminExitSection()
    {
        return 'getAdminExitSection';
    }
    
    /**
     * Get admin members section
     * 
     * @return string
     */
    private function getAdminMembersSection()
    {
        $kick = filter_input(INPUT_GET, 'kick', FILTER_VALIDATE_INT);
        $rank = filter_input(INPUT_GET, 'rank', FILTER_VALIDATE_INT);
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $new_rank = filter_input(INPUT_POST, 'newrang', FILTER_VALIDATE_INT);
        
        if (isset($kick) 
            && $this->_alliance->hasAccess(AllianceRanks::kick)
            && $kick != $this->_alliance->getCurrentAlliance()->getAllianceOwner()) {
            
            $this->Alliance_Model->exitAlliance(
                $this->getAllianceId(),
                $kick
            );
            
        }
         
        if (isset($new_rank) 
            && isset($id) 
            && $id != $this->_alliance->getCurrentAlliance()->getAllianceOwner()) {

            $ranks = $this->_alliance->getCurrentAllianceRankObject();
            
            if ($ranks->getRankById($new_rank - 1) != null) {
                
                $this->Alliance_Model->updateUserRank($id, $new_rank);
            }
         }
        
        $sort_by_field = filter_input(INPUT_GET, 'sort1');
        $sort_by_order = filter_input(INPUT_GET, 'sort2');
        $sort_by_order_rules = [1 => 2, 2 => 1];
        
        $members = $this->Alliance_Model->getAllianceMembers(
            $this->_user['user_ally_id'], $sort_by_field, $sort_by_order
        );
        
        $position = 0;
        $members_list = [];

        foreach($members as $member) {
            
            $position++;

            $members_list[] = [
                'position' => $position,
                'user_name' => $member['user_name'],
                'user_id' => $member['user_id'],
                'dpath' => DPATH,
                'write_message' => $this->getLang()['write_message'],
                'user_ally_range' => $this->buildAdminMembersRankBlock($member['user_id'], $member['user_ally_rank_id'], $rank),
                'points' => FormatLib::prettyNumber($member['user_statistic_total_points']),
                'user_galaxy' => $member['user_galaxy'],
                'user_system' => $member['user_system'],
                'coords' => FormatLib::prettyCoords($member['user_galaxy'], $member['user_system'], $member['user_planet']),
                'user_ally_register_time' => Timing_library::formatDefaultTime($member['user_ally_register_time']),
                'online_time' => Timing_library::formatDaysTime($member['user_onlinetime']),
                'actions' => $this->buildAdminMembersActionBlock($member['user_id'], $member['user_name'], $rank)
            ];
        }
        
        return $this->getTemplate()->set(
            'alliance/alliance_admin_members_view',
            array_merge(
                $this->getLang(),
                [
                    'total' => $position,
                    's' => isset($sort_by_order_rules[$sort_by_order]) ? $sort_by_order_rules[$sort_by_order] : 1,
                    'list_of_members' => $members_list
                ]
            )
        );
    }
    
    /**
     * Get admin name section
     * 
     * @return string
     */
    private function getAdminNameSection()
    {
        return 'getAdminNameSection';
    }
    
    /**
     * Get admin requests section
     * 
     * @return string
     */
    private function getAdminRequestsSection()
    {
        return 'getAdminRequestsSection';
    }
    
    /**
     * Get admin rights section
     * 
     * @return string
     */
    private function getAdminRightsSection()
    {
        $post = filter_input_array(INPUT_POST);
        $delete = filter_input(INPUT_GET, 'd', FILTER_VALIDATE_INT);

        $ranks = $this->_alliance->getCurrentAllianceRankObject();
        
        // Create a new rank
        if (isset($post['newrangname'])) {

            $ranks->addNew(
                $post['newrangname']
            );

            $this->Alliance_Model->updateAllianceRanks(
                $this->getAllianceId(),
                $ranks->getAllRanksAsJsonString()
            );
        }

        // edit rights for each rank
        if (isset($post['id'])) {

            foreach ($post['id'] as $id) {
                
                $ranks->editRankById(
                    $id,
                    [
                        AllianceRanks::delete => (isset($post['u' . $id . 'r1']) && $this->_alliance->isOwner()) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::kick => isset($post['u' . $id . 'r2']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::applications => isset($post['u' . $id . 'r3']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::view_member_list => isset($post['u' . $id . 'r4']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::application_management => isset($post['u' . $id . 'r5']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::administration => isset($post['u' . $id . 'r6']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::online_status => isset($post['u' . $id . 'r7']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::send_circular => isset($post['u' . $id . 'r8']) ? SwitchInt::on : SwitchInt::off,
                        AllianceRanks::right_hand => isset($post['u' . $id . 'r9']) ? SwitchInt::on : SwitchInt::off
                    ]
                );
            }

            $this->Alliance_Model->updateAllianceRanks(
                $this->getAllianceId(),
                $ranks->getAllRanksAsJsonString()
            );
        }

        // delete a rank
        if (isset($delete)) {

            $ranks->deleteRankById($delete);

            $this->Alliance_Model->updateAllianceRanks(
                $this->getAllianceId(),
                $ranks->getAllRanksAsJsonString()
            );
        }
        
        // build the UI
        $list_of_ranks = [];
        
        if (is_array($ranks->getAllRanksAsArray())) {
            
            foreach($ranks->getAllRanksAsArray() as $rank_id => $details) {
                
                $r1 = '<b>-</b>';
                
                if ($this->_alliance->isOwner()) {
                    $r1 = '<input type="checkbox" name="u' . $rank_id . 'r1"' . (($details['rights'][AllianceRanks::delete] == SwitchInt::on) ? ' checked="checked"' : '') . '>';
                }

                $list_of_ranks[] = [
                    'dpath' => DPATH,
                    'rank_id' => $rank_id,
                    'rank_name' => $details['rank'],
                    'r1' => $r1,
                    'checked_r2' => (($details['rights'][AllianceRanks::kick] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r3' => (($details['rights'][AllianceRanks::applications] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r4' => (($details['rights'][AllianceRanks::view_member_list] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r5' => (($details['rights'][AllianceRanks::application_management] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r6' => (($details['rights'][AllianceRanks::administration] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r7' => (($details['rights'][AllianceRanks::online_status] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r8' => (($details['rights'][AllianceRanks::send_circular] == SwitchInt::on) ? ' checked="checked"' : ''),
                    'checked_r9' => (($details['rights'][AllianceRanks::right_hand] == SwitchInt::on) ? ' checked="checked"' : ''),
                ];   
            }
        }
        
        return $this->getTemplate()->set(
            'alliance/alliance_admin_laws_view',
            array_merge(
                $this->getLang(),
                [
                    'dpath' => DPATH,
                    'list_of_ranks' => $list_of_ranks
                ]
            )
        );
    }
    
    /**
     * Get admin tag section
     * 
     * @return string
     */
    private function getAdminTagSection()
    {
        return 'getAdminTagSection';
    }
    
    /**
     * Get admin transfer section
     * 
     * @return string
     */
    private function getAdminTransferSection()
    {
        return 'getAdminTransferSection';
    }
    
    
    
    /**
     * 
     * BLOCKS
     * 
     */
    
    
    
    /**
     * 
     * @return string
     */
    private function buildPublicRequestsBlock()
    {
        if (!$this->_user['user_ally_id'] 
            && !$this->_user['user_ally_request'] 
            && !$this->_alliance->getCurrentAlliance()->getAllianceRequestNotAllow()) {

            $url = FunctionsLib::setUrl(
                'game.php?page=alliance&mode=apply&allyid=' . $this->getAllianceId(),
                $this->getLang()['al_click_to_send_request'],
                $this->getLang()['al_click_to_send_request']
            );

            return '<tr><th>' . $this->getLang()['al_request'] . '</th><th>' . $url . '</th></tr>';
        }

        return '';
    }
    
    /**
     * Build the image block
     * 
     * @return array
     */
    private function buildImageBlock()
    {
        $image = $this->_alliance->getCurrentAlliance()->getAllianceImage();
        
        if (!empty($image)) {

            return '<tr><th colspan="2">' . FunctionsLib::setImage($image, $image) . '</td></tr>';
        }

        return '';   
    }
    
    /**
     * Build the tag block
     * 
     * @return array
     */
    private function buildTagBlock()
    {
        return [
            'detail_title' => $this->getLang()['al_ally_info_tag'],
            'detail_content' => $this->_alliance->getCurrentAlliance()->getAllianceTag()
        ];
    }
    
    /**
     * Build the name block
     * 
     * @return array
     */
    private function buildNameBlock()
    {
        return [
            'detail_title' => $this->getLang()['al_ally_info_name'],
            'detail_content' => $this->_alliance->getCurrentAlliance()->getAllianceName()
        ];
    }
    
    /**
     * Build the members block
     * 
     * @return array
     */
    private function buildMembersBlock()
    {
        $list_of_members = '';
        
        if ($this->_alliance->hasAccess(AllianceRanks::view_member_list)) {
            
            $list_of_members = ' (' . FunctionsLib::setUrl('game.php?page=alliance&mode=memberslist', '', $this->getLang()['al_user_list']) . ')';
        }
        
        return [
            'detail_title' => $this->getLang()['al_ally_info_members'],
            'detail_content' => $this->_alliance->getCurrentAlliance()->getAllianceMembers() . $list_of_members
        ];
    }
    
    /**
     * Build the ranks block
     * 
     * @return array
     */
    private function buildRankBlock()
    {
        $rank = $this->getUserRank($this->_user['user_id'], $this->_user['user_ally_rank_id']);
        $admin_area = '';

        if ($this->_alliance->hasAccess(AllianceRanks::administration)) {
            
            $admin_area = ' (' . FunctionsLib::setUrl('game.php?page=alliance&mode=admin&edit=ally', '', $this->getLang()['al_manage_alliance']) . ')';
        }
        
        return [
            'detail_title' => $this->getLang()['al_rank'],
            'detail_content' => $rank . $admin_area
        ];
    }
    
    /**
     * Build the requests block
     * 
     * @return array
     */
    private function buildRequestsBlock()
    {
        $requests = '';
        $count = $this->Alliance_Model->getAllianceRequestsCount(
                $this->_alliance->getCurrentAlliance()->getAllianceId()
            )['total_requests'];

        if ($this->_alliance->hasAccess(AllianceRanks::application_management) && $count != 0) {
            
            $requests = FunctionsLib::setUrl(
                'game.php?page=alliance&mode=admin&edit=requests',
                '',
                $count . ' ' . $this->getLang()['al_new_requests']
            );
        }
        
        return [
            'detail_title' => $this->getLang()['al_requests'],
            'detail_content' => $requests 
        ];
    }
    
    /**
     * Build the circular message block
     * 
     * @return array
     */
    private function buildCircularBlock()
    {
        if ($this->_alliance->hasAccess(AllianceRanks::send_circular)) {
            
            return [
                'detail_title' => $this->getLang()['al_circular_message'],
                'detail_content' => FunctionsLib::setUrl('game.php?page=alliance&mode=circular', '', $this->getLang()['al_send_circular_message'])
            ];
        }
    }
    
    /**
     * Build the description block
     * 
     * @return array
     */
    private function buildDescriptionBlock()
    {
        $description = $this->getLang()['al_description_message'];
        $alliance_description = $this->_alliance->getCurrentAlliance()->getAllianceDescription();
        
        if ($alliance_description != '') {

            $description = nl2br($this->_bbcode->bbCode($alliance_description)) . '</th></tr>';
        }

        return '<tr><th colspan="2" height="100px">' . $description . '</th></tr>';
    }
    
    /**
     * Build the web block
     * 
     * @return array
     */
    private function buildWebBlock()
    {
        $alliance_web = '-';
        $alliance_web_url = $this->_alliance->getCurrentAlliance()->getAllianceWeb();
        
        if ($alliance_web_url != '') {

            $url = FunctionsLib::prepUrl($alliance_web_url);
            $alliance_web = FunctionsLib::setUrl($url, '', $url, 'target="_blank"');
        }

        return [
            'detail_title' => $this->getLang()['al_web_text'],
            'detail_content' => $alliance_web
        ];
    }
    
    /**
     * Build the description block
     * 
     * @return array
     */
    private function buildTextBlock()
    {
        return nl2br($this->_bbcode->bbCode($this->_alliance->getCurrentAlliance()->getAllianceText()));
    }
    
    /**
     * Build the leave block
     * 
     * @return array
     */
    private function buildLeaveBlock()
    {
        if (!$this->_alliance->isOwner()) {
            
            return $this->getTemplate()->set('alliance/alliance_leave_view', $this->getLang());
        }

        return '';
    }
    
    
    
    /**
     * 
     * OTHER METHODS
     * 
     */
    
    
    
    /**
     * Get user rank
     * 
     * @param int $member_id      Member ID
     * @param int $member_rank_id Member Rank ID
     * 
     * @return string
     */
    private function getUserRank($member_id, $member_rank_id)
    {
        if ($this->_alliance->getCurrentAlliance()->getAllianceOwner() == $member_id) {
            
            $owner_rank = $this->_alliance->getCurrentAlliance()->getAllianceOwnerRange();
            
            if (empty($owner_rank)) {
                
                return $this->getLang()['al_founder_rank_text'];
            }
            
            return $owner_rank;
        }
        
        if ($member_rank_id == 0) {
            
            return $this->getLang()['al_new_member_rank_text'];
        }
        
        $ranks = $this->_alliance->getCurrentAllianceRankObject();
        
        return $ranks->getRankById($member_rank_id - 1)['rank'];
    }
    
    /**
     * Build the admin members rank block
     * 
     * @param int $member_id
     * @param int $member_rank_id
     * @param int $requested_rank
     * 
     * @return string
     */
    private function buildAdminMembersRankBlock($member_id, $member_rank_id, $requested_rank = 0)
    {
        $rank = $this->getUserRank($member_id, $member_rank_id);
        
        if ($requested_rank != $member_id) {
            
            return $rank;
        }
        
        $ranks = $this->_alliance->getCurrentAllianceRankObject();
        
        $options = '<option onclick="document.edit_user_rank.submit();" value="0">' . $this->getLang()['al_new_member_rank_text'] . '</option>';
        
        if (is_array($ranks->getAllRanksAsArray())) {

            foreach ($ranks->getAllRanksAsArray() as $id => $rank) {

                $selected = '';
                
                if ($member_rank_id - 1 == $id) {
                    
                    $selected = ' selected=selected';
                }
                
                $options = '<option onclick="document.edit_user_rank.submit();" value="' . ($id + 1) . '"' . $selected . '>' . $rank['rank'] . '</option>'; 
            }
        }
            
        return $this->getTemplate()->set(
            'alliance/alliance_admin_members_edit', 
            array_merge(
                $this->getLang(),
                [
                    'options' => $options
                ]
            )
        );
    }
    
    /**
     * Build the admin members action block
     * 
     * @param int    $member_id      Member ID
     * @param string $member_name    Member Name
     * @param int    $requested_rank Requested Rank
     * 
     * @return string
     */
    private function buildAdminMembersActionBlock($member_id, $member_name, $requested_rank = 0)
    {
        $kick_user = '';
        $change_rank = '';
        
        if ($this->_alliance->getCurrentAlliance()->getAllianceOwner() == $member_id
            or $requested_rank == $member_id) {
            
            return '-';
        }
        
        if ($this->_alliance->hasAccess(AllianceRanks::kick)) {
            
            $action = 'game.php?page=alliance&mode=admin&edit=members&kick=' . $member_id; 
            $content = FunctionsLib::setImage(DPATH . 'alliance/abort.gif');
            $attributes = 'onclick="javascript:return confirm(\'' . strtr($this->getLang()['al_confirm_remove_member'], ['%s' => $member_name]) . '\');"';
            $kick_user = FunctionsLib::setUrl($action, '', $content, $attributes);
        }
        
        if ($this->_alliance->hasAccess(AllianceRanks::administration)) {

            $action = 'game.php?page=alliance&mode=admin&edit=members&rank=' . $member_id; 
            $content = FunctionsLib::setImage(DPATH . 'alliance/key.gif');
            $change_rank = FunctionsLib::setUrl($action, '', $content);
        }
        
        if (empty($kick_user) && empty($change_rank)) {
            
            return '-';
        }
        
        return $kick_user . $change_rank;
    }
    
    /**
     * 
     * OLD METHODS
     * OLD METHODS
     * OLD METHODS
     * OLD METHODS
     * OLD METHODS
     * 
     */

    /**
     * method ally_admin
     * param
     * return the admin page for someone with an alliance
     */
    private function ally_admin()
    {
        $parse = $this->getLang();

        if ($this->_user['user_ally_id'] != 0 && $this->_user['user_ally_request'] == 0) {
            $edit = ( isset($_GET['edit']) ? $_GET['edit'] : NULL );

            switch ($edit) {
                case ($edit == 'requests' && $this->have_access($this->_ally['alliance_owner'], $this->permissions['check_requests']) === true):

                    $show = isset($_GET['show']) ? (int) $_GET['show'] : null;

                    if (isset($_POST['action']) && ( $_POST['action'] == $this->getLang()['al_acept_request'] )) {

                        $this->Alliance_Model->addUserToAlliance($show, $this->_ally['alliance_id']);

                        FunctionsLib::sendMessage($show, $this->_user['user_id'], '', 3, $this->_ally['alliance_tag'], $this->getLang()['al_you_was_acceted'] . $this->_ally['alliance_name'], $this->getLang()['al_hi_the_alliance'] . $this->_ally['alliance_name'] . $this->getLang()['al_has_accepted'] . $_POST['text']);

                        FunctionsLib::redirect('game.php?page=alliance&mode=admin&edit=ally');
                    } elseif (isset($_POST['action']) && ( $_POST['action'] == $this->getLang()['al_decline_request'] ) && $_POST['action'] != '') {

                        $this->Alliance_Model->removeUserFromAlliance($show);

                        FunctionsLib::sendMessage($show, $this->_user['user_id'], '', 3, $this->_ally['alliance_tag'], $this->getLang()['al_you_was_declined'] . $this->_ally['alliance_name'], $this->getLang()['al_hi_the_alliance'] . $this->_ally['alliance_name'] . $this->getLang()['al_has_declined'] . $_POST['text']);

                        FunctionsLib::redirect('game.php?page=alliance&mode=admin&edit=ally');
                    }

                    $i = 0;
                    $query = $this->Alliance_Model->getAllianceRequests($this->_ally['alliance_id']);

                    $s = [];
                    $parse['list_of_requests'] = [];
                    $parse['request'] = '';
                    $parse['no_requests'] = '';

                    if ($query->num_rows > 0) {

                        while ($r = $this->_db->fetchArray($query)) {

                            if (isset($show) && $r['user_id'] == $show) {

                                $s[$show]['username'] = $r['user_name'];
                                $s[$show]['ally_request_text'] = nl2br($r['user_ally_request_text']);
                                $s[$show]['id'] = $r['user_id'];
                            }

                            $r['username'] = $r['user_name'];
                            $r['id'] = $r['user_id'];

                            $r['time'] = date(
                                FunctionsLib::readConfig('date_format_extended'), $r['user_ally_register_time']
                            );

                            $parse['list_of_requests'][] = $r;

                            $i++;
                        }
                    }

                    if (count($parse['list_of_requests']) == 0) {

                        $parse['no_requests'] = "<tr><th colspan=2>" . $this->getLang()['al_no_requests'] . "</th></tr>";
                    }

                    if (isset($show) && $show != 0 && $query->num_rows != 0) {

                        $s[$show]['Request_from'] = str_replace(
                            '%s', $s[$show]['username'], $this->getLang()['al_request_from']
                        );
                        $parse['request'] = $this->getTemplate()->set(
                            'alliance/alliance_admin_request_form', array_merge($s[$show], $this->getLang())
                        );
                    }

                    $parse['ally_tag'] = $this->_ally['alliance_tag'];
                    $parse['There_is_hanging_request'] = str_replace('%n', $i, $this->getLang()['al_no_request_pending']);

                    return $this->getTemplate()->set(
                            'alliance/alliance_admin_request_view', $parse
                    );
                    break;

                case ( $edit == 'name' && $this->have_access($this->_ally['alliance_owner'], $this->permissions['admin_alliance']) === true ):

                    $alliance_name = '';

                    if ($_POST) {
                        $alliance_name = $this->validateName($_POST['nametag']);

                        $this->Alliance_Model->updateAllianceName($this->_ally['alliance_id'], $alliance_name);
                    }

                    $parse['caso'] = ( $alliance_name == '' ) ? str_replace('%s', $this->_ally['alliance_name'], $this->getLang()['al_change_title']) : str_replace('%s', $alliance_name, $this->getLang()['al_change_title']);
                    $parse['caso_titulo'] = $this->getLang()['al_new_name'];

                    return $this->getTemplate()->set('alliance/alliance_admin_rename', $parse);

                    break;

                case ( $edit == 'tag' && $this->have_access($this->_ally['alliance_owner'], $this->permissions['admin_alliance']) === true ):

                    $alliance_tag = '';

                    if ($_POST) {
                        $alliance_tag = $this->validateTag($_POST['nametag']);

                        $this->Alliance_Model->updateAllianceTag($this->_ally['alliance_id'], $alliance_tag);
                    }

                    $parse['caso'] = ( $alliance_tag == '' ) ? str_replace('%s', $this->_ally['alliance_tag'], $this->getLang()['al_change_title']) : str_replace('%s', $alliance_tag, $this->getLang()['al_change_title']);
                    $parse['caso_titulo'] = $this->getLang()['al_new_tag'];

                    return $this->getTemplate()->set('alliance/alliance_admin_rename', $parse);

                    break;

                case ( $edit == 'exit' && $this->have_access($this->_ally['alliance_owner'], $this->permissions['disolve_alliance']) === true ):

                    $this->Alliance_Model->deleteAlliance($this->_ally['alliance_id']);

                    FunctionsLib::redirect('game.php?page=alliance');

                    break;

                case ( $edit == 'transfer' && $this->have_access($this->_ally['alliance_owner'], $this->permissions['admin_alliance']) === true ):

                    $alliance_ranks = unserialize($this->_ally['alliance_ranks']);

                    if (isset($_POST['newleader'])) {

                        $this->Alliance_Model->transferAlliance($this->_user['user_ally_id'], $this->_user['user_id'], $_POST['newleader']);

                        FunctionsLib::redirect('game.php?page=alliance');
                    }

                    $parse['list_of_members'] = [];

                    if ($this->_ally['alliance_owner'] != $this->_user['user_id']) {
                        FunctionsLib::redirect('game.php?page=alliance');
                    } else {
                        $listuser = $this->Alliance_Model->getAllianceMembersById($this->_user['user_ally_id']);
                        $righthand = $this->getLang();
                        $righthand['righthand'] = '';

                        while ($u = $this->_db->fetchArray($listuser)) {
                            if ($this->_ally['alliance_owner'] != $u['user_id']) {
                                if ($u['user_ally_rank_id'] != 0) {
                                    if ($alliance_ranks[$u['user_ally_rank_id'] - 1]['rechtehand'] == 1) {
                                        $righthand['righthand'] .= "\n<option value=\"" . $u['user_id'] . "\"";
                                        $righthand['righthand'] .= ">";
                                        $righthand['righthand'] .= "" . $u['user_name'];
                                        $righthand['righthand'] .= "&nbsp;[" . $alliance_ranks[$u['user_ally_rank_id'] - 1]['name'];
                                        $righthand['righthand'] .= "]&nbsp;&nbsp;</option>";
                                    }
                                }
                            }
                            $righthand['dpath'] = DPATH;
                        }

                        $parse['list_of_members'][] = $righthand;

                        return $this->getTemplate()->set('alliance/alliance_admin_transfer_view', $parse);
                    }

                    break;
            }
        }
    }

    /**
     * method message_box
     * param $title
     * param $message
     * param $goto
     * param $button
     * param $two_lines
     * return shows a special message box with actions & buttons
     */
    private function messageBox($title, $message, $goto = '', $button = ' ok ', $two_lines = false)
    {
        $parse['goto'] = $goto;
        $parse['title'] = $title;
        $parse['message'] = $message;
        $parse['button'] = $button;
        $template = ( $two_lines ) ? 'alliance_message_box_row_two' : 'alliance_message_box_row_one';

        $parse['message_box_row'] = $this->getTemplate()->set('alliance/' . $template, $parse);

        return $this->getTemplate()->set('alliance/alliance_message_box', $parse);
    }

    /**
     * method check_tag
     * param $alliance_tag
     * return the validated the ally tag
     */
    private function validateTag($alliance_tag)
    {
        $alliance_tag = trim($alliance_tag);
        $alliance_tag = htmlspecialchars_decode($alliance_tag, ENT_QUOTES);

        if ($alliance_tag == '' OR is_null($alliance_tag) OR ( strlen($alliance_tag) < 3 ) OR ( strlen($alliance_tag) > 8 )) {
            FunctionsLib::message($this->getLang()['al_tag_required'], "game.php?page=alliance&mode=make", 2);
            exit;
        }

        $check_tag = $this->Alliance_Model->checkAllianceTag($alliance_tag);

        if ($check_tag) {
            FunctionsLib::message(str_replace('%s', $alliance_tag, $this->getLang()['al_tag_already_exists']), "game.php?page=alliance&mode=make", 2);
            exit;
        }

        return $alliance_tag;
    }

    /**
     * method check_name
     * param $alliance_name
     * return the validated the ally name
     */
    private function validateName($alliance_name)
    {
        $alliance_name = trim($alliance_name);
        $alliance_name = htmlspecialchars_decode($alliance_name, ENT_QUOTES);

        if ($alliance_name == '' OR is_null($alliance_name) OR ( strlen($alliance_name) < 3 ) OR ( strlen($alliance_name) > 30 )) {
            FunctionsLib::message($this->getLang()['al_name_required'], "game.php?page=alliance&mode=make", 2);
            exit;
        }

        $check_name = $this->Alliance_Model->checkAllianceName($alliance_name);

        if ($check_name) {
            FunctionsLib::message(str_replace('%s', $alliance_name, $this->getLang()['al_name_already_exists']), "game.php?page=alliance&mode=make", 2);
            exit;
        }

        return $alliance_name;
    }
}

/* end of alliance.php */
