(($) => {
  const viewMoreBtn = document.querySelector(".single-product .view-more");
  const viewLocationBtn = document.querySelector(
    ".single-product .view-location"
  );

  if (!viewMoreBtn || !viewLocationBtn) return;

  viewMoreBtn.addEventListener("click", openTab("additional_information"));
  viewLocationBtn.addEventListener("click", openTab("location"));

  function openTab(tabId) {
    return (e) => {
      e.preventDefault();
      const tab = document.querySelector(`.${tabId}_tab a`);
      $(tab).click();
      $(tab)[0].scrollIntoView({
        behavior: "smooth",
        block: "center",
      });
    };
  }
})(jQuery);
