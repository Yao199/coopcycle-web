<?php

namespace AppBundle\Form;

use AppBundle\Entity\Address;
use AppBundle\Entity\Base\GeoCoordinates;
use Doctrine\Common\Persistence\ManagerRegistry;
use libphonenumber\PhoneNumberFormat;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints;
use Misd\PhoneNumberBundle\Validator\Constraints\PhoneNumber as AssertPhoneNumber;

class AddressType extends AbstractType
{
    private $translator;
    private $doctrine;
    private $country;

    public function __construct(TranslatorInterface $translator, ManagerRegistry $doctrine, $country)
    {
        $this->translator = $translator;
        $this->doctrine = $doctrine;
        $this->country = $country;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('streetAddress', SearchType::class, [
                'label' => 'form.address.streetAddress.label',
                'attr' => [
                    // autocomplete="off" doesn't work in Chrome
                    // https://developer.mozilla.org/en-US/docs/Web/Security/Securing_your_site/Turning_off_form_autocompletion
                    // https://bugs.chromium.org/p/chromium/issues/detail?id=468153#c164
                    'autocomplete' => uniqid()
                ]
            ])
            ->add('postalCode', HiddenType::class, [
                'required' => false,
                'label' => 'form.address.postalCode.label'
            ])
            ->add('addressLocality', HiddenType::class, [
                'required' => false,
                'label' => 'form.address.addressLocality.label'
            ])
            ->add('floor', TextType::class, [
                'required' => false,
                'label' => 'form.address.floor.label'
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => 'form.address.description.label',
                'attr' => ['rows' => '3', 'placeholder' => 'form.address.description.placeholder']
            ])
            ->add('latitude', HiddenType::class, [
                'mapped' => false,
            ])
            ->add('longitude', HiddenType::class, [
                'mapped' => false,
            ]);

        if (true === $options['extended']) {
            $builder
                ->add('firstName', TextType::class, [
                    'label' => 'form.address.firstName.label',
                    'required' => false,
                ])
                ->add('lastName', TextType::class, [
                    'label' => 'form.address.lastName.label',
                    'required' => false,
                ])
                ->add('company', TextType::class, [
                    'label' => 'form.address.company.label',
                    'required' => false,
                ]);
        }

        if (true === $options['with_telephone']) {
            $builder
                ->add('telephone', PhoneNumberType::class, [
                    'format' => PhoneNumberFormat::NATIONAL,
                    'default_region' => strtoupper($this->country),
                    'required' => false,
                    'constraints' => [
                        new AssertPhoneNumber()
                    ],
                ]);
        }

        if (true === $options['with_name']) {
            $builder
                ->add('name', TextType::class, [
                    'required' => false,
                    'label' => 'form.address.name.label',
                    'attr' => ['placeholder' => 'form.address.name.placeholder']
                ]);
        }

        $constraints = [
            new Constraints\NotBlank(),
            new Constraints\Type(['type' => 'numeric']),
        ];

        // Make sure latitude/longitude is valid
        $latLngListener = function (FormEvent $event) use ($constraints) {
            $form = $event->getForm();
            $address = $event->getData();

            $streetAddress = $form->get('streetAddress')->getData();
            if (!empty($streetAddress)) {
                $latitude = $form->get('latitude')->getData();
                $longitude = $form->get('longitude')->getData();

                $validator = Validation::createValidator();

                $latitudeViolations = $validator->validate($latitude, $constraints);
                $longitudeViolations = $validator->validate($longitude, $constraints);

                if (count($latitudeViolations) > 0 || count($longitudeViolations) > 0) {

                    $message = 'form.address.streetAddress.error.noLatLng';
                    $error = new FormError($this->translator->trans($message), $message);

                    $form->get('streetAddress')->addError($error);
                } else {
                    $address->setGeo(new GeoCoordinates($latitude, $longitude));
                }
            }
        };

        $builder->addEventListener(FormEvents::SUBMIT, $latLngListener);
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($options) {
            $form = $event->getForm();
            $address = $event->getData();
            if (null !== $address) {
                if ($geo = $address->getGeo()) {
                    $form->get('latitude')->setData($geo->getLatitude());
                    $form->get('longitude')->setData($geo->getLongitude());
                }
            }
        });

    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => Address::class,
            'extended' => false,
            'with_telephone' => false,
            'with_name' => false
        ));
    }
}
