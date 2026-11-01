(() => {
  const eventSelect = document.querySelector("#event_id");
  const categorySelect = document.querySelector("#category_id");
  const contestantSelect = document.querySelector("#contestant_id");
  const quantityField = document.querySelector("#quantity-field");
  const quantityInput = document.querySelector("#vote_quantity");
  const paidNote = document.querySelector("#paid-voting-note");
  const castButton = document.querySelector("#cast-vote-button");
  const simulateButton = document.querySelector("#simulate-payment-button");
  if (!eventSelect || !categorySelect || !contestantSelect || !quantityField || !quantityInput || !paidNote) return;

  const updateOptions = () => {
    const eventId = eventSelect.value;
    const mode = eventSelect.selectedOptions[0]?.dataset.votingMode || "";
    const previousCategory = categorySelect.value;
    [...categorySelect.options].forEach((option) => {
      if (!option.value) return;
      option.hidden = option.dataset.eventId !== eventId;
    });
    if (categorySelect.selectedOptions[0]?.hidden) categorySelect.value = "";
    if (!categorySelect.value && previousCategory) {
      categorySelect.value = [...categorySelect.options].find((option) => !option.hidden && option.value)?.value || "";
    }

    const categoryId = categorySelect.value;
    [...contestantSelect.options].forEach((option) => {
      if (!option.value) return;
      option.hidden = option.dataset.eventId !== eventId || option.dataset.categoryId !== categoryId;
    });
    if (contestantSelect.selectedOptions[0]?.hidden) contestantSelect.value = "";

    const restricted = mode === "restricted_single";
    quantityField.hidden = restricted || mode === "";
    quantityInput.required = !restricted && mode !== "";
    quantityInput.value = "1";
    paidNote.hidden = mode !== "paid_multiple";
    if (castButton) castButton.hidden = mode === "paid_multiple";
    if (simulateButton) simulateButton.hidden = mode !== "paid_multiple";
  };

  eventSelect.addEventListener("change", updateOptions);
  categorySelect.addEventListener("change", updateOptions);
  updateOptions();
})();
