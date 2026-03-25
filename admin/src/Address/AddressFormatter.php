<?php

namespace Simp\Pindrop\Modules\admin\src\Address;

use CommerceGuys\Addressing\AddressFormat\AddressFormat;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\Country\CountryRepository;
use CommerceGuys\Addressing\Subdivision\SubdivisionRepository;
use DI\DependencyException;
use DI\NotFoundException;

class AddressFormatter
{
    protected string $addressTemplate = "@admin/address/address_template.twig";

    protected array $addressFields = [];
    private string $countryCode;
    protected array $countries = [];
    private array $states = [];
    protected array $counties = [];
    protected array $cities = [];

    public function __construct(string $countryCode)
    {
        $this->countryCode = $countryCode;
        $repository = new AddressFormatRepository();
        $addressFormat = $repository->get($countryCode);
        $this->addressFields = $addressFormat->getRequiredFields();

        $countryRepository = new CountryRepository();
        $this->countries = $countryRepository->getAll();

        $subdivisionRepository = new SubdivisionRepository();
        $this->states = $subdivisionRepository->getList([$countryCode]);
    }

    /**
     * Set counties (Level 2 subdivisions)
     */
    public function setCounties(string $stateCode): void
    {
        $subdivisionRepository = new SubdivisionRepository();

        $this->counties = $subdivisionRepository->getList([
            'country_code' => $this->countryCode,
            'parent_code'  => $stateCode,
        ]);
    }

    /**
     * Set cities (Level 3 subdivisions)
     */
    public function setCities(string $countyCode): void
    {
        $subdivisionRepository = new SubdivisionRepository();

        $this->cities = $subdivisionRepository->getList([
            'country_code' => $this->countryCode,
            'parent_code'  => $countyCode,
        ]);
    }

    public function getAddressFields(): array
    {
        return $this->addressFields;
    }

    public function getCountryCode(): string {
        return $this->countryCode;
    }

    public function setCountryCode(string $countryCode): self {
        return new self($countryCode);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getAddressTemplate(string $sectionName, array $options = []): string {

        return \getAppContainer()->get('twig')->render($this->addressTemplate, [
            'fields' => $this->addressFields,
            'countryCode' => $this->countryCode,
            'countries'  => $this->countries,
            'states'     => $this->states,
            'counties'   => $this->counties,
            'cities'     => $this->cities,
            'section_name' => $sectionName,
            ...$options
        ]);
    }
}