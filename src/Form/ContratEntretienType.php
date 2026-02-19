<?php

namespace App\Form;

use App\DTO\ContratEntretienDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class ContratEntretienType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // === SECTION : Informations générales ===
            ->add('numeroContrat', IntegerType::class, [
                'label' => 'N° de contrat',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Auto-généré si vide',
                    'min' => 1,
                ],
                'required' => false, // Auto-généré si vide
            ])
            ->add('dateSignature', DateType::class, [
                'label' => 'Date de signature',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'required' => true,
            ])
            ->add('dateDebutContrat', DateType::class, [
                'label' => 'Date de début',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'required' => true,
            ])
            ->add('dateFinContrat', DateType::class, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
                'required' => false,
            ])
            ->add('duree', ChoiceType::class, [
                'label' => 'Durée du contrat',
                'choices' => [
                    '1 an' => '1 an',
                    '2 ans' => '2 ans',
                    '3 ans' => '3 ans',
                    '4 ans' => '4 ans',
                    '5 ans' => '5 ans',
                    'Autre' => 'autre',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => '-- Sélectionner --',
                'required' => true,
            ])
            ->add('isTaciteReconduction', CheckboxType::class, [
                'label' => 'Tacite reconduction',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'label_attr' => ['class' => 'form-check-label'],
            ])

            // === SECTION : Financier ===
            ->add('valorisation', ChoiceType::class, [
                'label' => 'Valorisation',
                'choices' => [
                    'Forfait' => 'forfait',
                    'Gré à gré' => 'gre_a_gre',
                    'Présentiel' => 'presentiel',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => '-- Sélectionner --',
                'required' => false,
            ])
            ->add('modeRevalorisation', ChoiceType::class, [
                'label' => 'Mode de revalorisation',
                'choices' => [
                    'Aucune' => 'aucune',
                    'Pourcentage fixe' => 'pourcentage',
                    'Indice INSEE' => 'indice_insee',
                    'Négociation' => 'negociation',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => '-- Sélectionner --',
                'required' => false,
            ])
            ->add('tauxRevalorisation', NumberType::class, [
                'label' => 'Taux de revalorisation (%)',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex: 2.50',
                    'step' => '0.01',
                    'min' => 0,
                    'max' => 100,
                ],
                'required' => false,
                'scale' => 2,
            ])
            ->add('montantAnnuelHt', MoneyType::class, [
                'label' => 'Montant annuel HT',
                'currency' => 'EUR',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '0.00',
                ],
                'required' => false,
            ])
            ->add('montantVisiteCEA', MoneyType::class, [
                'label' => 'Montant visite CEA',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control', 'placeholder' => '0.00'],
                'required' => false,
            ])
            ->add('montantVisiteCE1', MoneyType::class, [
                'label' => 'Montant visite CE1',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control', 'placeholder' => '0.00'],
                'required' => false,
            ])
            ->add('montantVisiteCE2', MoneyType::class, [
                'label' => 'Montant visite CE2',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control', 'placeholder' => '0.00'],
                'required' => false,
            ])
            ->add('montantVisiteCE3', MoneyType::class, [
                'label' => 'Montant visite CE3',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control', 'placeholder' => '0.00'],
                'required' => false,
            ])
            ->add('montantVisiteCE4', MoneyType::class, [
                'label' => 'Montant visite CE4',
                'currency' => 'EUR',
                'attr' => ['class' => 'form-control', 'placeholder' => '0.00'],
                'required' => false,
            ])

            // === SECTION : Parc ===
            ->add('nombreEquipement', IntegerType::class, [
                'label' => 'Nombre d\'équipements',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'placeholder' => '0',
                ],
                'required' => true,
            ])
            ->add('nombreVisite', IntegerType::class, [
                'label' => 'Nombre de visites / an',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'placeholder' => 'Ex: 2',
                ],
                'required' => true,
            ])

            // === SECTION : Planification ===
            ->add('datePrevisionnelle1', TextType::class, [
                'label' => 'Date prévisionnelle visite 1',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex: Mars 2026',
                ],
                'required' => false,
            ])
            ->add('datePrevisionnelle2', TextType::class, [
                'label' => 'Date prévisionnelle visite 2',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex: Septembre 2026',
                ],
                'required' => false,
            ])

            // === SECTION : Documents ===
            ->add('contratPdfFile', FileType::class, [
                'label' => 'PDF du contrat signé',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => '.pdf',
                ],
            ])

            // === SECTION : Notes ===
            ->add('notes', TextareaType::class, [
                'label' => 'Notes internes',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Informations complémentaires...',
                ],
                'required' => false,
            ])

            // === Hidden : liaison client ===
            ->add('idContact', HiddenType::class)
            ->add('contactId', HiddenType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContratEntretienDTO::class,
        ]);
    }
}