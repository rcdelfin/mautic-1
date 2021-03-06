<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Mautic\FormBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\FormBundle\Model\FormModel;
use Mautic\FormBundle\Model\SubmissionModel;

/**
 * Class CampaignSubscriber
 *
 * @package Mautic\EmailBundle\EventListener
 */
class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var FormModel
     */
    protected $formModel;

    /**
     * @var SubmissionModel
     */
    protected $formSubmissionModel;

    /**
     * CampaignSubscriber constructor.
     * 
     * @param MauticFactory $factory
     * @param FormModel $formModel
     * @param SubmissionModel $formSubmissionModel
     */
    public function __construct(MauticFactory $factory, FormModel $formModel, SubmissionModel $formSubmissionModel)
    {
        $this->formModel = $formModel;
        $this->formSubmissionModel = $formSubmissionModel;
        
        parent::__construct($factory);
    }

    /**
     * @return array
     */
    static public function getSubscribedEvents()
    {
        return array(
            CampaignEvents::CAMPAIGN_ON_BUILD => array('onCampaignBuild', 0),
            FormEvents::FORM_ON_SUBMIT        => array('onFormSubmit', 0),
            FormEvents::ON_CAMPAIGN_TRIGGER_DECISION => ['onCampaignTriggerDecision', 0],
            FormEvents::ON_CAMPAIGN_TRIGGER_CONDITION   => ['onCampaignTriggerCondition', 0]
        );
    }

    /**
     * Add the option to the list
     *
     * @param CampaignBuilderEvent $event
     */
    public function onCampaignBuild(CampaignBuilderEvent $event)
    {
        $trigger = array(
            'label'       => 'mautic.form.campaign.event.submit',
            'description' => 'mautic.form.campaign.event.submit_descr',
            'formType'    => 'campaignevent_formsubmit',
            'eventName'   => FormEvents::ON_CAMPAIGN_TRIGGER_DECISION,
            'callback'    => array('\\Mautic\\FormBundle\\Helper\\CampaignEventHelper', 'validateFormSubmit')
        );
        $event->addLeadDecision('form.submit', $trigger);

        $trigger = array(
            'label'       => 'mautic.form.campaign.event.field_value',
            'description' => 'mautic.form.campaign.event.field_value_descr',
            'formType'    => 'campaignevent_form_field_value',
            'formTheme'   => 'MauticFormBundle:FormTheme\FieldValueCondition',
            'callback'    => array('\\Mautic\\FormBundle\\Helper\\CampaignEventHelper', 'validateFormValue'),
            'eventName'   => FormEvents::ON_CAMPAIGN_TRIGGER_CONDITION
        );
        $event->addLeadCondition('form.field_value', $trigger);
    }

    /**
     * Trigger campaign event for when a form is submitted
     *
     * @param SubmissionEvent $event
     */
    public function onFormSubmit(SubmissionEvent $event)
    {
        $form = $event->getSubmission()->getForm();
        $this->factory->getModel('campaign.event')->triggerEvent('form.submit', $form, 'form.submit' . $form->getId());
    }

    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerDecision(CampaignExecutionEvent $event)
    {
        $eventDetails = $event->getEventDetails();
        
        if ($eventDetails === null) {
            return $event->setResult(true);
        }

        $limitToForms = $event->getConfig()['forms'];

        //check against selected forms
        if (!empty($limitToForms) && !in_array($eventDetails->getId(), $limitToForms)) {
            return $event->setResult(false);
        }

        return $event->setResult(true);
    }

    /**
     * @param CampaignExecutionEvent $event
     */
    public function onCampaignTriggerCondition(CampaignExecutionEvent $event)
    {
        $lead = $event->getLead();
        
        if (!$lead || !$lead->getId()) {
            return $event->setResult(false);
        }

        $operators = $this->formModel->getFilterExpressionFunctions();
        $form      = $this->formModel->getRepository()->findOneById($event->getConfig()['form']);

        if (!$form || !$form->getId()) {
            return $event->setResult(false);
        }

        $result = $this->formSubmissionModel->getRepository()->compareValue(
            $lead->getId(),
            $form->getId(),
            $form->getAlias(),
            $event->getConfig()['field'],
            $event->getConfig()['value'],
            $operators[$event->getConfig()['operator']]['expr']
        );
        
        return $event->setResult($result);
    }
}
