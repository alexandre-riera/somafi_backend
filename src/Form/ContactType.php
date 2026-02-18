<?php

namespace App\Form;

use App\DTO\ContactDTO;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de création/édition d'un client (contact).
 * 
 * Bindé sur ContactDTO (pas sur une entité Doctrine directe,
 * car il y a 13 classes ContactSXX séparées par agence).
 * 
 * Le service ContactService (Phase 2.2) se chargera de l'insertion
 * DBAL dans la bonne table contact_sXX.
 */
class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];

        $builder
            // ── Identité client ──────────────────────────
            ->add('raisonSociale', TextType::class, [
                'label'    => 'Raison sociale *',
                'attr'     => [
                    'placeholder' => 'Ex : MONDIAL RELAY MONTPELLIER',
                    'class'       => 'form-control',
                    'autofocus'   => true,
                ],
                'required' => true,
            ])
            ->add('nom', TextType::class, [
                'label'    => 'Nom',
                'attr'     => [
                    'placeholder' => 'Nom du responsable / contact',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])
            ->add('prenom', TextType::class, [
                'label'    => 'Prénom',
                'attr'     => [
                    'placeholder' => 'Prénom du contact',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])
            ->add('idSociete', TextType::class, [
                'label'    => 'ID Société',
                'attr'     => [
                    'placeholder' => 'Identifiant société (optionnel)',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])

            // ── Identifiant Kizeo ────────────────────────
            ->add('idContact', TextType::class, [
                'label'    => 'ID Contact (Kizeo)',
                'attr'     => [
                    'placeholder' => 'Ex : MR-MPL-001',
                    'class'       => 'form-control',
                ],
                'help'     => 'Identifiant utilisé pour la synchronisation avec Kizeo Forms.',
                'required' => false,
            ])

            // ── Adresse ──────────────────────────────────
            ->add('adressep1', TextType::class, [
                'label'    => 'Adresse (ligne 1) *',
                'attr'     => [
                    'placeholder' => 'Numéro et nom de rue',
                    'class'       => 'form-control',
                ],
                'required' => true,
            ])
            ->add('adressep2', TextType::class, [
                'label'    => 'Adresse (ligne 2)',
                'attr'     => [
                    'placeholder' => 'Complément d\'adresse (bâtiment, étage...)',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])
            ->add('cpostalp', TextType::class, [
                'label'    => 'Code postal *',
                'attr'     => [
                    'placeholder' => 'Ex : 34000',
                    'class'       => 'form-control',
                    'maxlength'   => 5,
                    'inputmode'   => 'numeric',
                ],
                'required' => true,
            ])
            ->add('villep', TextType::class, [
                'label'    => 'Ville *',
                'attr'     => [
                    'placeholder' => 'Ex : MONTPELLIER',
                    'class'       => 'form-control',
                ],
                'required' => true,
            ])

            // ── Coordonnées ──────────────────────────────
            ->add('telephone', TelType::class, [
                'label'    => 'Téléphone',
                'attr'     => [
                    'placeholder' => 'Ex : 04 67 12 34 56',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label'    => 'Email',
                'attr'     => [
                    'placeholder' => 'Ex : contact@entreprise.fr',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])
            ->add('contactSite', TextType::class, [
                'label'    => 'Contact sur site',
                'attr'     => [
                    'placeholder' => 'Nom du contact sur site',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])

            // ── Bancaire ─────────────────────────────────
            ->add('rib', TextType::class, [
                'label'    => 'RIB',
                'attr'     => [
                    'placeholder' => 'Références bancaires (optionnel)',
                    'class'       => 'form-control',
                ],
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ContactDTO::class,
            'is_edit'    => false,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
