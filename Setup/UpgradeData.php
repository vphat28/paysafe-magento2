<?php

namespace Paysafe\Payment\Setup;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Eav\Model\Config;
use Magento\Customer\Model\Customer;
use Magento\Framework\Setup\UpgradeDataInterface;

class UpgradeData implements UpgradeDataInterface {
	private $eavSetupFactory;

	public function __construct(EavSetupFactory $eavSetupFactory, Config $eavConfig)
	{
		$this->eavSetupFactory = $eavSetupFactory;
		$this->eavConfig       = $eavConfig;
	}

	public function upgrade(
		ModuleDataSetupInterface $setup,
		ModuleContextInterface $context
	)
	{
		if (version_compare($context->getVersion(), '1.0.4', '<')) {
			$eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
			$eavSetup->addAttribute(
				\Magento\Customer\Model\Customer::ENTITY,
				'paysafe_stored_cards',
				[
					'type'         => 'text',
					'label'        => 'Paysafe Stored Cards',
					'input'        => 'text',
					'required'     => false,
					'visible'      => false,
					'user_defined' => false,
					'position'     => 999,
					'system'       => 0,
				]
			);
			$newAttr = $this->eavConfig->getAttribute(Customer::ENTITY, 'paysafe_stored_cards');

			$newAttr->setData(
				'used_in_forms',
				[]

			);
			$newAttr->save();
		}
	}
}
