<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once dirname(__FILE__).'/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../behaviors/CMessageBehavior.php';
require_once dirname(__FILE__).'/../../include/helpers/CDataHelper.php';
require_once dirname(__FILE__).'/../traits/PreprocessingTrait.php';

/**
 * @backup items
 */
class testItemTypeSelection extends CWebTest {

	use PreprocessingTrait;

	const HOSTID = 40001;
	const LLDID = 90001;
	const PROTOTYPE = true;

	public $itemid;

	/**
	 * Attach Behaviors to the test.
	 *
	 * @return array
	 */
	public function getBehaviors() {
		return ['class' => CMessageBehavior::class];
	}

	public static function getItemData() {
		return [
			[
				[
					'fill' => [
						'Name' => 'Character',
						'Key' => 'agent.hostmetadata'
					],
					'type' => 'Character'
				]
			],
			[
				[
					'fill' => [
						'Name' => 'Numeric unsigned',
						'Key' => 'agent.ping'
					],
					'type' => 'Numeric (unsigned)'
				]
			],
			[
				[

					'fill' => [
						'Name' => 'Numeric float',
						'Key' => 'net.udp.service.perf[service]'
					],
					'type' => 'Numeric (float)'
				]
			],
			[
				[
					'fill' => [
						'Name' => 'Log',
						'Key' => 'eventlog[name]'
					],
					'type' => 'Log'
				]
			],
			[
				[
					'fill' => [
						'Name' => 'Text',
						'Key' => 'net.if.discovery'
					],
					'type' => 'Text'
				]
			],
			[
				[
					'fill' => [
						'Name' => 'Custom key',
						'Key' => 'custom.key'
					],
					'type' => 'Numeric (unsigned)'
				]
			],
			[
				[
					'fill' => [
						'Name' => 'Custom key 2',
						'Key' => 'custom.key2',
						'Type of information' => 'Text'
					],
					'type' => 'Text',
					'hint' => false
				]
			],
			[
				[
					'fill' => [
						'Name' => 'Test Info Hint',
						'Key' => 'net.if.list',
						'Type of information' => 'Log'
					],
					'hint' => true,
					'hint_text' => 'This type of information may not match the key.',
					'automatic_type' => 'Text'
				]
			]
		];
	}

	/**
	 * @dataProvider getItemData
	 */
	public function testItemTypeSelection_Item($data) {
		$this->checkItemTypeSelection($data);
	}

	/**
	 * @dataProvider getItemData
	 */
	public function testItemTypeSelection_ItemPrototype($data) {
		$this->checkItemTypeSelection($data, self::PROTOTYPE);
	}


	public function checkItemTypeSelection($data, $prototype = false) {
		$link = ($prototype)
			? 'disc_prototypes.php?form=create&parent_discoveryid='.self::LLDID.'&context=host'
			: 'items.php?form=create&hostid='.self::HOSTID.'&context=host';

		$this->page->login()->open($link)->waitUntilReady();
		$form = $this->query('id', ($prototype) ? 'item-prototype-form' : 'item-form')->asForm()->waitUntilReady()->one();

		// Make names unique for items and prototypes.
		$data['fill']['Name'] = $data['fill']['Name'].microtime();
		$form->fill($data['fill']);

		// Check hintbox text.
		if (CTestArrayHelper::get($data, 'hint')) {
			$icon = $this->query('id:js-item-type-hint')->waitUntilClickable()->one();
			$icon->click();
			$this->assertEquals($data['hint_text'],
					$form->query('xpath://div[@class="hintbox-wrap"]')->waitUntilPresent()->one()->getText()
			);
		}
		elseif (CTestArrayHelper::get($data, 'hint') === false) {
			$this->assertFalse($form->query('id:js-item-type-hint')->waitUntilPresent()->one()->isVisible());
		}
		else {
			// Check that type changed to automatic.
			$this->assertEquals($data['type'], $form->getField('Type of information')->getValue());
		}

		$form->submit();
		$this->assertMessage(TEST_GOOD, ($prototype) ? 'Item prototype added' : 'Item added');

		// Check item saved in db.
		$this->assertEquals(1, CDBHelper::getCount('SELECT * FROM items '.
				'WHERE key_ ='.zbx_dbstr($data['fill']['Key']).
					' AND name ='.zbx_dbstr($data['fill']['Name'])
			)
		);

		// Check saved item form in Frontend.
		$id = CDBHelper::getValue('SELECT * FROM items '.
				'WHERE key_ ='.zbx_dbstr($data['fill']['Key']).
					' AND name ='.zbx_dbstr($data['fill']['Name'])
		);

		$saved_link = ($prototype)
			? 'disc_prototypes.php?form=update&parent_discoveryid='.self::LLDID.'&itemid='.$id.'&context=host'
			: 'items.php?form=update&hostid='.self::HOSTID.'&itemid='.$id.'&context=host';

		$this->page->open($saved_link)->waitUntilReady();

		$form->invalidate();
		$form->checkValue($data['fill']);

		if (CTestArrayHelper::get($data, 'hint')) {
			// Check that info disappears when preprocessing step is added.
			$form->selectTab('Preprocessing');
			$this->addPreprocessingSteps([['type' => 'Regular expression', 'parameter_1' => 'pattern', 'parameter_2' => 'output']]);
			$this->assertEquals($data['fill']['Type of information'], $form->getField('Type of information')->getValue());

			$form->selectTab(($prototype) ? 'Item prototype' : 'Item');
			$this->assertTrue($icon->isStalled());
			$form->submit();
			$this->assertMessage(TEST_GOOD, ($prototype) ? 'Item prototype updated' : 'Item updated');

			// Check that custom type remained in saved form.
			$this->page->open($saved_link)->waitUntilReady();
			$form->invalidate();
			$this->assertEquals($data['fill']['Type of information'], $form->getField('Type of information')->getValue());

			// Check that type changes to automatic when preprocessing is cleared.
			$form->selectTab('Preprocessing');
			$form->query('xpath:.//li[@data-step="0"]')->waitUntilPresent()->one()->query('button:Remove')
					->waitUntilClickable()->one()->click();

			$form->selectTab(($prototype) ? 'Item prototype' : 'Item');
			$this->assertEquals($data['automatic_type'], $form->getField('Type of information')->getValue());

			// Check saved form.
			$form->submit();
			$this->assertMessage(TEST_GOOD, ($prototype) ? 'Item prototype updated' : 'Item updated');
			$this->page->open($saved_link)->waitUntilReady();
			$form->invalidate();
			$this->assertEquals($data['automatic_type'], $form->getField('Type of information')->getValue());
		}
		else {
			$this->assertEquals($data['type'], $form->getField('Type of information')->getValue());
		}
	}
}

