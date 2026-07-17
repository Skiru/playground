export interface ContentCatalog {
  common: {
    siteTitle: string;
    backToCatalog: string;
  };
  navigation: {
    mainNavigationLabel: string;
    placesCatalog: string;
  };
  home: {
    eyebrow: string;
    heading: string;
    lede: string;
    queryLabel: string;
    queryPlaceholder: string;
    cityLabel: string;
    ageLabel: string;
    anyOption: string;
    ageOptionUnder2: string;
    ageOption3to5: string;
    ageOption6to9: string;
    ageOption10Plus: string;
    showPlacesButton: string;
    popularHeading: string;
    selectCategoryType: string;
  };
  places: {
    resultsEyebrow: string;
    resultsHeadingPlural: (count: number) => string;
    placeMetaSeparator: string;
    indoor: string;
    outdoor: string;
    freeEntry: string;
    noResults: string;
    previousPage: string;
    nextPage: string;
    paginationLabel: string;
    paginationPageInfo: (current: number, total: number) => string;
    formSearch: string;
    formCity: string;
    formCategory: string;
    formAge: string;
    formLat: string;
    formLng: string;
    formRadius: string;
    formIndoor: string;
    formAmenitiesHeader: string;
    filterButton: string;
    allCitiesOption: string;
    allCategoriesOption: string;
    verifiedPlace: string;
    aboutPlace: string;
    amenitiesHeading: string;
    infoHeading: string;
    addressLabel: string;
    spaceLabel: string;
    entryLabel: string;
    freeEntryLabel: string;
    paidEntryLabel: string;
    spaceAnd: string;
  };
  map: {
    mapExplorerHeading: string;
    mapUnavailableSummary: string;
    loading: string;
    spatialViewEyebrow: string;
    mapResultsHeading: string;
    interactiveMapLabel: string;
    refreshing: string;
    retryButton: string;
    placesOnMap: (count: number) => string;
    noPlacesInArea: string;
    missingConfig: string;
    noWebGl: string;
    loadModuleError: string;
    loadStyleError: string;
    apiError: (status: number) => string;
    refreshError: string;
  };
  errors: {
    generalError: string;
    error404: string;
    notFoundDetails: string;
    errorDetailsPrefix: string;
  };
  metadata: {
    homeTitle: string;
    homeDescription: string;
    catalogTitle: string;
    catalogDescription: string;
    placeDetailTitleSuffix: (name: string) => string;
    placeDetailDescriptionFallback: string;
  };
}
