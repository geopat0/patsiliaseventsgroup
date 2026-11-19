const hostedContactForm = document.querySelector('[data-host-contact]');
const contactStatus = hostedContactForm.querySelector('[data-contact-status]');
const submitContact = hostedContactForm.querySelector('[type="submit"]');

hostedContactForm.addEventListener('submit', async event => {
  event.preventDefault();
  if (submitContact.disabled) return;

  const values = new URLSearchParams(new FormData(hostedContactForm));
  submitContact.disabled = true;
  contactStatus.textContent = 'Στέλνουμε το αίτημά σας…';

  try {
    const response = await fetch(hostedContactForm.action, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: values
    });
    const result = await response.json();
    contactStatus.textContent = result.message;
    if (response.ok && result.ok) {
      hostedContactForm.reset();
      hostedContactForm.elements.equipment.value = values.get('equipment');
    } else if (result.errors) {
      const [field, message] = Object.entries(result.errors)[0] || [];
      if (message) contactStatus.textContent = message;
      if (field) hostedContactForm.elements.namedItem(field)?.focus();
    }
  } catch {
    contactStatus.textContent = 'Η αποστολή δεν επιβεβαιώθηκε. Μπορείτε να επικοινωνήσετε τηλεφωνικά ή με email.';
  } finally {
    submitContact.disabled = false;
  }
});
