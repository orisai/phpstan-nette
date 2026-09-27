<?php declare(strict_types = 1);

namespace Tests\OriPhpstan\Nette\Doubles\Forms\MatrixAssert;

use Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm;
use Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer;
use Tests\OriPhpstan\Nette\Doubles\Forms\Entity\UserInfo;
use Tests\OriPhpstan\Nette\Doubles\Forms\Entity\ProjectFilterBase;
use Nette\Forms\Container;
use function Tests\OriPhpstan\Nette\Doubles\Forms\t;
use function OriPhpstan\Nette\Forms\Testing\assertComponent;

final class Integration
{

	/** @var array<int, string> */
	private array $contractToItems = [
		91 => '3 měsíce',
		182 => '6 měsíců',
		273 => '9 měsíců',
		365 => '12 měsíců',
		456 => '15 měsíců',
		547 => '18 měsíců',
		638 => '21 měsíců',
		730 => '24 měsíců',
	];

	private string $lang = 'cs';

	public function integration(): void
	{
		$form = new ApplicationForm();

		$form->getElementPrototype()->class('profile-form form-horizontal');

		$mainContainer = $form->addContainer('filter');
		$mainContainer->addHidden('id');

		$ageContainer = $mainContainer->addContainer('age');
		$ageContainer->addText('from', t('Věk'))
			->addCondition($form::FILLED)
			->addRule($form::INTEGER, t('Věk musí být číselná hodnota'))
			->addRule($form::MIN, t('Minimální věk musí být %s let'), 15);
		$ageContainer->addText('to')
			->addCondition($form::FILLED)
			->addRule($form::INTEGER, t('Věk musí být číselná hodnota'))
			->addRule($form::MAX, t('Maximální věk musí být do %s let'), 90);

		$ageContainer['from']->addConditionOn($ageContainer['to'], $form::FILLED)
			->addConditionOn($ageContainer['from'], $form::FILLED)
			->addRule(
				$form::MAX,
				t('Věk v prvním sloupci musí být menší, než ve sloupci druhém'),
				$ageContainer['to'],
			);

		$mainContainer->addRadioList(
			'only_certified',
			t('Certifikovaný shopper'),
			[2 => t('ano'), 1 => t('ne')],
		);

		$mainContainer->addSelect(
			'education_level',
			t('Minimální ukončené vzdělání'),
			$this->pairs(),
		)
			->setPrompt(t('-- vyberte --'));

		$mainContainer->addRadioList(
			'sex',
			t('Pohlaví'),
			[1 => t('muž'), 2 => t('žena')],
		);

		$mainContainer->addRadioList(
			'business_license',
			t('Živnostenský list'),
			[2 => t('ano'), 1 => t('ne')],
		);

		$mainContainer->addSelect(
			'employment_type',
			t('Typ spolupráce'),
			ProjectFilterBase::getEmploymentTypes(),
		)
			->setPrompt(t('-- vyberte --'));

		$mainContainer->addCheckboxList(
			'exclude_groups',
			t('Vyloučit uživatele'),
			$this->pairs(),
		);

		$markContainer = $mainContainer->addContainer('mark');
		$markContainer->addText('from', t('Známka'))
			->addCondition($form::FILLED)
			->addRule($form::FLOAT, t('Známka musí být číselná hodnota'))
			->addRule($form::MIN, t('Minimální známka musí být %s'), 1);
		$markContainer->addText('to')
			->addCondition($form::FILLED)
			->addRule($form::FLOAT, t('Známka musí být číselná hodnota'))
			->addRule($form::MAX, t('Maximální známka musí být do %s'), 5);

		$markContainer['from']->addConditionOn($markContainer['to'], $form::FILLED)
			->addConditionOn($markContainer['from'], $form::FILLED)
			->addRule(
				$form::MAX,
				t('Známka v prvním sloupci musí být menší, než ve sloupci druhém'),
				$markContainer['to'],
			);

		$mainContainer->addSelect('last_login', t('Poslední aktivita'), [
			183 => t('méně než 6 měsíců'),
			365 => t('méně než 1 rok'),
		])->setPrompt(t('-- vyberte --'));

		$operatorCount = 0;
		$voiceTariffList = $this->pairs();
		$voiceTariff = $mainContainer->addDynamic(
			'voice_tariff',
			function (FormContainer $container) use ($form, &$operatorCount, $voiceTariffList): void {
				$container->addHidden('id');
				$container->addSelect('operator', t('Operátor'), $voiceTariffList)
					->setPrompt(t('-- vyberte --'));
				$container->addCheckbox('opposite', t('nemá'));

				$container->addRadioList(
					'type',
					t('Typ'),
					[0 => t('paušál'), 1 => t('kredit')],
				)
					->setHtmlAttribute('class', 'product-type')
					->addCondition($form::EQUAL, 0)
					->toggle('tariff-' . $operatorCount)
					->toggle('indefinite-' . $operatorCount);
				$container->addRadioList(
					'business_type',
					t('Služební/firemní tarif'),
					[1 => 'Ano', 0 => 'Ne'],
				)
					->addCondition($form::EQUAL, 1)
					->toggle('manage-service-' . $operatorCount);
				$this->addContractDateSelect($container, 'contract', $this->contractToItems);
				$container->addRadioList(
					'indefinite',
					t('Smlouva na dobu neurčitou'),
					[1 => 'Ano', 0 => 'Ne'],
				)
					->setOption('id', 'indefinite-' . $operatorCount);
				$container->addRadioList(
					'manage_service',
					t('Administrátor služby'),
					[1 => 'Ano', 0 => 'Ne'],
				)
					->setOption('id', 'manage-service-' . $operatorCount);

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
				$operatorCount++;
			},
			0,
		);

		$voiceTariff->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$fixedInternetContainer = $mainContainer->addContainer('fixed_internet');
		$fixedInternetList = $this->pairs();
		$fixedInternet = $fixedInternetContainer->addDynamic(
			'brands',
			function (FormContainer $container) use ($fixedInternetList): void {
				$container->addHidden('id');
				$container->addSelect('operator', t('Poskytovatel'), $fixedInternetList)
					->setPrompt(t('-- vyberte --'));
				$container->addCheckbox('opposite', t('nemá'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$fixedInternet->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$mobilePhoneContainer = $mainContainer->addContainer('mobile_phone');
		$mobilePhoneContainer->addRadioList(
			'use_smartphone',
			t('Má smartphone'),
			[1 => t('Ano'), 0 => t('Ne')],
		)
			->addCondition($form::EQUAL, 1)
			->toggle('mobile_phone_container');

		$mobilePhoneContainer->addSelect(
			'brand_id',
			t('Výrobce'),
			$this->pairs(),
		)
			->setPrompt(t('-- vyberte --'));

		$mainContainer->addRadioList(
			'recording',
			t('Může nahrávat'),
			[1 => t('Ano'), 0 => t('Ne')],
		);

		$driverLicence = $mainContainer->addContainer('driver_licence');
		$driverLicence->addRadioList(
			'use_driverLicence',
			t('Má řidičský průkaz'),
			[1 => t('Ano'), 0 => t('Ne')],
		)
			->addCondition($form::EQUAL, 1)
			->toggle('driver_licence_container');

		$driverLicence->addHidden('id');
		$driverLicence->addCheckboxList('driverLicence', null, $this->pairs());

		$carContainer = $mainContainer->addContainer('car');
		$this->addDateSelect($carContainer, 'from', false, 50);
		$this->addDateSelect($carContainer, 'to', false, 50, 1);

		$carContainer['from']['year']->addConditionOn($carContainer['to']['year'], $form::FILLED)
			->addRule(
				$form::MAX,
				t(
					'První hodnota roku výroby auta nesmí být větší, než hodnota druhá',
				),
				$carContainer['to']['year'],
			);

		$carsList = $this->pairs();
		$cars = $carContainer->addDynamic('brands', function (FormContainer $container) use ($carsList): void {
			$container->addHidden('id');
			$container->addSelect('brand', t('Výrobce'), $carsList)
				->setPrompt(t('-- vyberte --'));
			$container->addCheckbox('opposite', t('nemá'));

			$container->addSubmit('removeNode')
				->setValidationScope([])
				->addRemoveOnClick(function (): void {
				});
		}, 0);

		$cars->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$motorBikeContainer = $mainContainer->addContainer('motorbike');
		$this->addDateSelect($motorBikeContainer, 'from', false, 50);
		$this->addDateSelect($motorBikeContainer, 'to', false, 50, 1);

		$motorBikeContainer['from']['year']->addConditionOn($motorBikeContainer['to']['year'], $form::FILLED)
			->addRule(
				$form::MAX,
				t(
					'První hodnota roku výroby motocyklu nesmí být větší, než hodnota druhá',
				),
				$motorBikeContainer['to']['year'],
			);

		$motorbikeList = $this->pairs();
		$motorbike = $motorBikeContainer->addDynamic(
			'brands',
			function (FormContainer $container) use ($motorbikeList): void {
				$container->addHidden('id');
				$container->addSelect('brand', t('Výrobce'), $motorbikeList)
					->setPrompt(t('-- vyberte --'));
				$container->addCheckbox('opposite', t('nemá'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$motorbike->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$disableEmployersList = $this->pairs();
		$disableEmployers = $mainContainer->addDynamic(
			'disable_employers',
			function (FormContainer $container) use ($disableEmployersList): void {
				$container->addHidden('id');
				$container->addSelect(
					'employer',
					t('Zakázaný zaměstnavatel'),
					$disableEmployersList,
				)
					->setPrompt(t('-- vyberte --'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$disableEmployers->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$disableEmployersTypeList = $this->pairs();
		$disableEmployersType = $mainContainer->addDynamic(
			'disable_employers_type',
			function (FormContainer $container) use ($disableEmployersTypeList): void {
				$container->addHidden('id');
				$container->addSelect(
					'employer_type',
					t('Zakázaný typ zaměstnavatele'),
					$disableEmployersTypeList,
				)
					->setPrompt(t('-- vyberte --'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$disableEmployersType->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$languageList = $this->pairs();
		$language = $mainContainer->addDynamic('language', function (FormContainer $container) use ($languageList): void {
			$container->addHidden('id');
			$container->addSelect('language', t('Jazyk'), $languageList)
				->setPrompt(t('-- vyberte --'));

			$container->addSubmit('removeNode')
				->setValidationScope([])
				->addRemoveOnClick(function (): void {
				});
		}, 0);

		$language->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$insuranceContainer = $mainContainer->addContainer('insurance');
		$insuranceContainer->addCheckboxList(
			'product_type',
			t('Využívá tyto produkty'),
			$this->pairs(),
		)
			->setHtmlAttribute('class', 'product-type');

		$insuranceList = $this->pairs();
		$insurance = $insuranceContainer->addDynamic(
			'brands',
			function (FormContainer $container) use ($insuranceList): void {
				$container->addHidden('id');
				$container->addSelect('name', t('Pojišťovna'), $insuranceList)
					->setPrompt(t('-- vyberte --'));
				$container->addCheckbox('opposite', t('nemá'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$insurance->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$buildingInsuranceContainer = $mainContainer->addContainer('building_insurance');
		$buildingInsuranceContainer->addCheckboxList(
			'product_type',
			t('Využívá tyto produkty'),
			$this->pairs(),
		)
			->setHtmlAttribute('class', 'product-type');

		$buildingInsuranceList = $this->pairs();
		$buildingInsurance = $buildingInsuranceContainer->addDynamic(
			'brands',
			function (FormContainer $container) use ($buildingInsuranceList): void {
				$container->addHidden('id');
				$container->addSelect('name', t('Spořitelna'), $buildingInsuranceList)
					->setPrompt(t('-- vyberte --'));
				$container->addCheckbox('opposite', t('nemá'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$buildingInsurance->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$bankContainer = $mainContainer->addContainer('bank');
		$bankContainer->addCheckboxList(
			'product_type',
			t('Využívá tyto produkty'),
			$this->pairs(),
		)
			->setHtmlAttribute('class', 'product-type bank-product-type');

		$bankList = $this->pairs();
		$bank = $bankContainer->addDynamic('brands', function (FormContainer $container) use ($bankList): void {
			$container->addHidden('id');
			$container->addSelect('name', t('Banka'), $bankList)
				->setPrompt(t('-- vyberte --'));
			$container->addCheckbox('opposite', t('nemá'));

			$container->addSubmit('removeNode')
				->setValidationScope([])
				->addRemoveOnClick(function (): void {
				});
		}, 0);

		$bank->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$electricityContainer = $mainContainer->addContainer('electricity');
		$electricityList = $this->pairs();
		$electricity = $electricityContainer->addDynamic(
			'brands',
			function (FormContainer $container) use ($electricityList): void {
				$container->addHidden('id');
				$container->addSelect('operator', t('Dodavatel'), $electricityList)
					->setPrompt(t('-- vyberte --'));
				$container->addCheckbox('opposite', t('nemá'));

				$container->addSubmit('removeNode')
					->setValidationScope([])
					->addRemoveOnClick(function (): void {
					});
			},
			0,
		);

		$electricity->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$gasContainer = $mainContainer->addContainer('gas');
		$gasList = $this->pairs();
		$gas = $gasContainer->addDynamic('brands', function (FormContainer $container) use ($gasList): void {
			$container->addHidden('id');
			$container->addSelect('operator', t('Dodavatel'), $gasList)
				->setPrompt(t('-- vyberte --'));
			$container->addCheckbox('opposite', t('nemá'));

			$container->addSubmit('removeNode')
				->setValidationScope([])
				->addRemoveOnClick(function (): void {
				});
		}, 0);

		$gas->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		$clientList = $this->pairs();
		$client = $mainContainer->addDynamic('client', function (FormContainer $container) use ($clientList): void {
			$container->addHidden('id');
			$container->addSelect('client', t('Klient'), $clientList)
				->setPrompt(t('-- vyberte --'));
			$container->addCheckbox('opposite', t('má'));

			$container->addSubmit('removeNode')
				->setValidationScope([])
				->addRemoveOnClick(function (): void {
				});
		}, 0);

		$client->addSubmit('addNode')
			->setValidationScope([])
			->addCreateOnClick(false, function (): void {
			});

		if ($this->lang === 'sazka') {
			$sazkaContainer = $mainContainer->addContainer('sazka');
			$sazkaContainer->addSelect(
				'available',
				t('Počet dostupných poukázek:'),
				['0', '1 - 5', '5 - 10', '10 a více', '1 a více'],
			)
				->setPrompt(t('-- vyberte --'));

			$sazkaContainer->addSelect(
				'used',
				t('Počet již použitých poukázek:'),
				['0', '1 - 5', '5 - 10', '10 a více', '1 a více'],
			)
				->setPrompt(t('-- vyberte --'));
		}

		$tobaccoContainer = $mainContainer->addContainer('tobacco');
		$tobaccoContainer->addRadioList(
			'products_classic',
			t('Je pravidelným kuřákem klasických cigaret?'),
			[1 => t('Ano'), 0 => t('Ne')],
		);
		$tobaccoContainer->addRadioList(
			'products_electronic',
			t('Je pravidelným kuřákem elektronických cigaret?'),
			[1 => t('Ano'), 0 => t('Ne')],
		)
			->addCondition($form::EQUAL, 1)
			->toggle('tobacco_electronic_type_container');
		$tobaccoContainer->addCheckboxList(
			'products_electronic_type',
			null,
			UserInfo::getTobaccoProductsElectricTypeEnum(),
		)
			->addConditionOn($tobaccoContainer['products_electronic'], $form::EQUAL, 1)
			->setRequired(t('Vyberte prosím druh elektronické cigarety.'));
		$tobaccoContainer->addRadioList(
			'products_others',
			t('Je pravidelným kuřákem na jiné bázi?'),
			[1 => t('Ano'), 0 => t('Ne')],
		);

		assertComponent($form, <<<'OUTPUT'
			Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{
			  filter: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			    age: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      from: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			      to: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    },
			    bank: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        name: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			      product_type: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			    },
			    building_insurance: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        name: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			      product_type: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			    },
			    business_license: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 1|2|null>,
			    car: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        brand: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			      ...<IComponent>,
			    },
			    client: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      client: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			      id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			      opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			      removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			    }>>+ownKdyby\Replicator\Container{
			      addNode: Nette\Forms\Controls\SubmitButton,
			    },
			    disable_employers: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      employer: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			      id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			      removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			    }>>+ownKdyby\Replicator\Container{
			      addNode: Nette\Forms\Controls\SubmitButton,
			    },
			    disable_employers_type: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      employer_type: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			      id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			      removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			    }>>+ownKdyby\Replicator\Container{
			      addNode: Nette\Forms\Controls\SubmitButton,
			    },
			    driver_licence: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      driverLicence: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			      id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			      use_driverLicence: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			    },
			    education_level: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			    electricity: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        operator: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			    },
			    employment_type: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			    exclude_groups: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			    fixed_internet: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        operator: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			    },
			    gas: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        operator: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			    },
			    id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			    insurance: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        name: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			      product_type: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			    },
			    language: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			      language: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			      removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			    }>>+ownKdyby\Replicator\Container{
			      addNode: Nette\Forms\Controls\SubmitButton,
			    },
			    last_login: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, 183|365|null>,
			    mark: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      from: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			      to: Nette\Forms\Controls\TextInput<bool|float|int|string|Stringable|null, string>,
			    },
			    mobile_phone: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brand_id: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			      use_smartphone: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			    },
			    motorbike: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      brands: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			        brand: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			        id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			        opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			        removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      }>>+ownKdyby\Replicator\Container{
			        addNode: Nette\Forms\Controls\SubmitButton,
			      },
			      ...<IComponent>,
			    },
			    only_certified: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 1|2|null>,
			    recording: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			    sazka?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      available: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, 0|1|2|3|4|null>,
			      used: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, 0|1|2|3|4|null>,
			    },
			    sex: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 1|2|null>,
			    tobacco: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      products_classic: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			      products_electronic: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			      products_electronic_type: Nette\Forms\Controls\CheckboxList<bool|float|int|iterable<BackedEnum|bool|float|int|string|Stringable>|string|null, list<int|string>>,
			      products_others: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			    },
			    voice_tariff: Kdyby\Replicator\Container<array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{
			      business_type: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			      id: Nette\Forms\Controls\HiddenField<BackedEnum|bool|float|int|string|Stringable|null, string|null>,
			      indefinite: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			      manage_service: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			      operator: Nette\Forms\Controls\SelectBox<BackedEnum|int|string|null, int|string|null>,
			      opposite: Nette\Forms\Controls\Checkbox<bool|float|int|string|null, bool>,
			      removeNode: Tests\OriPhpstan\Nette\Doubles\Forms\Form\CustomSubmitButton,
			      type: Nette\Forms\Controls\RadioList<BackedEnum|int|string|null, 0|1|null>,
			      ...<IComponent>,
			    }>>+ownKdyby\Replicator\Container{
			      addNode: Nette\Forms\Controls\SubmitButton,
			    },
			  },
			}
			OUTPUT);
	}

	/**
	 * @return array<int, string>
	 */
	private function pairs(): array
	{
		return [];
	}

	/**
	 * @param string $name
	 * @param bool $showMonth
	 * @param int $year
	 * @param int $toFuture
	 * @param bool $reverse
	 * @param int $minYear
	 */
	private function addDateSelect(
		Container $container,
		$name,
		$showMonth = true,
		$year = 5,
		$toFuture = 0,
		$reverse = false,
		$minYear = null
	): Container
	{
		$newContainer = $container->addContainer($name);
		if ($showMonth) {
			$newContainer->addSelect('month', t('Měsíc'), $this->pairs())
				->setPrompt(t('-- vyberte --'));
		}

		$newContainer->addSelect(
			'year',
			t('Rok'),
			$this->pairs(),
		)
			->setPrompt(t('-- vyberte --'));

		return $newContainer;
	}

	/**
	 * @param array<int, string> $items
	 */
	private function addContractDateSelect(Container $container, string $name, array $items): Container
	{
		$newContainer = $container->addContainer($name);
		$newContainer->addSelect('from', null, $items)
			->setPrompt(t('-- vyberte --'));
		$newContainer->addSelect('to', null, $items)
			->setPrompt(t('-- vyberte --'));

		return $newContainer;
	}

}
