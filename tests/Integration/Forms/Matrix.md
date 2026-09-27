# Form Shape Combination Matrix

Receiver classes under test: `Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm` (AF), `Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm` (CF),
`Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer` (FC), `Tests\OriPhpstan\Nette\Doubles\Forms\Form\ContactForm` (NF), `Nette\Application\UI\Form` (NUF),
`Nette\Forms\Form` (NFF). Fixtures live in `Fixtures/MatrixAssert/<group>.php`; each scenario ends with
`assertComponent($form, '<expected>')`, where the expected is the rendered component shape (the read value type
below appears as each input's second generic parameter; see the fixture for the full block).

## Group 1 — value catalog (placement: top-level, receiver AF, name: constant)
| ID | add call | Expected describe() |
|----|----------|---------------------|
| C01 | addText('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| C02 | addText('a')->setNullable() | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: non-empty-string\|null} |
| C03 | addPassword('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| C04 | addTextArea('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| C05 | addEmail('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| C06 | addInteger('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|null} |
| C07 | addFloat('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: float\|null} |
| C08 | addCheckbox('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: bool} |
| C09 | addHidden('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string\|null} |
| C10 | addSelect('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|string\|null} |
| C11 | addRadioList('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|string\|null} |
| C12 | addMultiSelect('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: list<int\|string>} |
| C13 | addCheckboxList('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: list<int\|string>} |
| C14 | addUpload('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: Nette\Http\FileUpload|null} |
| C15 | addMultiUpload('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: list<Nette\Http\FileUpload>|null} |
| C16 | addDate('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: DateTimeImmutable\|null} |
| C17 | addTime('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: DateTimeImmutable\|null} |
| C18 | addDateTime('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: DateTimeImmutable\|null} |
| C19 | addDate('a')->setFormat(DateTimeControl::FormatTimestamp) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|null} |
| C20 | addColor('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| C21 | addSubmit('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |
| C22 | addButton('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string|null} |
| C23 | addImageButton('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |
| C24 | addReCaptcha('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |
| C25 | addProtection() | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |
| C26 | (empty) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |

## Group 2 — receiver variants (add: addText('a'), top-level)
| ID | receiver | Expected describe() |
|----|----------|---------------------|
| R01 | ApplicationForm | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| R02 | RawForm | Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm{a: string} |
| R03 | FormContainer (local var) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string} |
| R04 | ContactForm (stock Nette factories) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ContactForm{a: string} |
| R05 | Nette\Application\UI\Form | Nette\Application\UI\Form{a: string} |
| R06 | Nette\Forms\Form | Nette\Forms\Form{a: string} |
| R07 | union (AF in if, CF in else) then addText('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm\|Tests\OriPhpstan\Nette\Doubles\Forms\Form\RawForm{a: string} |
| R08 | non-form local (\stdClass) ->addText('a') | (no assertComponent — N/A; see Group 9 N03) |

## Group 3 — placement → presence (receiver AF, add addText('a'))
| ID | placement | Expected describe() |
|----|-----------|---------------------|
| P01 | top-level | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| P02 | if ($c) {…} | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P03 | if ($c) {…} else {…} (both add 'a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| P04 | if ($c) {add 'a'} elseif ($d) {add 'a'} (no else) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P05 | if {add 'a'} else {add 'b'} | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string, b?: string} |
| P06 | switch: 2 arms add 'a', default adds 'a' | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| P07 | switch: 2 arms add 'a', no default | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P08 | match arm expression add 'a' (one arm) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P09 | ternary cond ? add 'a' : add 'b' | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string, b?: string} |
| P10 | $x ?? add 'a' | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P11 | $x ??= add 'a' | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P12 | foreach (range as $i) { add 'a' } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P13 | while ($c) { add 'a' } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P14 | do { add 'a' } while ($c) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P15 | for (;;) { add 'a' } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P16 | try { add 'a' } catch { } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| P17 | try { } catch { } finally { add 'a' } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| P18 | if ($c) { return; } add 'a' (assert after) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| P19 | if ($c) { add 'a'; return; } add 'b' (assert at end) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string, b: string} |
| P20 | if ($c) { throw; } add 'a' | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |

## Group 4 — multiplicity (receiver AF, top-level)
| ID | adds | Expected describe() |
|----|------|---------------------|
| M01 | addText('a'); addText('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| M02 | addText('a'); addInteger('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|string\|null} |
| M03 | if {addText('a')} else {addInteger('a')} | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|string\|null} |
| M04 | addText('a'); if ($c) { addInteger('a') } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: int\|string\|null} |
| M05 | addContainer('a'); addText('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{}\|string} |

## Group 5 — name resolvability (receiver AF, addText, top-level)
| ID | name expr | Expected describe() |
|----|-----------|---------------------|
| N01 | 'a' (string lit) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| N02 | self::NAME ('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| N03 | 'pre_' . self::SUF ('x') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{pre_x: string} |
| N04 | $name (param, unknown) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{…+unknown(dynamic_name)} |
| N05 | "f_$i" in foreach | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{…+unknown(dynamic_name)} |

## Group 6 — nested containers & replicators (receiver AF)
| ID | structure | Expected describe() |
|----|-----------|---------------------|
| X01 | addContainer('c'); $c->addText('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{c: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}} |
| X02 | 3-level nested addContainer + leaf addInteger | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{b: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{c: int\|null}}} |
| X03 | addDynamic('d', fn(FormContainer $c) => $c->addText('a')) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{d: array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}>} |
| X04 | addContainer('c') in if; $c->addText('a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{c?: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{a: string}} |
| X05 | addDynamic('d', $factory) where $factory is a variable (non-enumerable) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{d: array<int, Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{}>, …+unknown(non_enumerable_closure)} |

## Group 7 — removal (receiver AF, top-level)
| ID | ops | Expected describe() |
|----|-----|---------------------|
| D01 | addText('a'); unset($form['a']) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |
| D02 | addText('a'); if ($c) { unset($form['a']) } | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a?: string} |
| D03 | addText('a'); $form->removeComponent($form['a']) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |

## Group 8 — explicit add forms (receiver AF, top-level)
| ID | op | Expected describe() |
|----|----|---------------------|
| E01 | $form['a'] = new TextInput() | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| E02 | $form->addComponent(new TextInput(), 'a') | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string} |
| E03 | $form['a'] = $builtInlineContainer (FormContainer with addText('x')) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: Tests\OriPhpstan\Nette\Doubles\Forms\Form\FormContainer{x: string}} |
| E04 | $form['a'] = $paramContainer (origin unresolved) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: *UNKNOWN*, …+unknown(unresolved_origin)} |

## Group 9 — unanalysable / vendor-magic (receiver AF, top-level)
| ID | op | Expected describe() |
|----|----|---------------------|
| U01 | $form->addText($name) [dynamic] | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{…+unknown(dynamic_name)} |
| U02 | $form->addSomethingUnknown() [unknown __call addX] | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{…+unknown(extension_method)} |
| U03 | addText('a'); $form->addText($name) | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{a: string, …+unknown(dynamic_name)} |
| U04 | addReCaptcha('a') only | Tests\OriPhpstan\Nette\Doubles\Forms\Form\ApplicationForm{} |
| N03(non-form) | (\stdClass)->addText('a') | (analyzer returns FormShape::empty() of class \stdClass: \stdClass{}) |

## Group 10 — integration
| ID | fixture | Expected |
|----|---------|----------|
| I01 | Real `UserProfileFilterFormControl::createComponentForm()` copied verbatim into a fixture, sentinel appended before `return $form;` | Snapshot stored in `Fixtures/Matrix/Integration.expected` (generated once, reviewed in approval) |
