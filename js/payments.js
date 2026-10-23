async function postJson(url, token, data) {
  const response = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(data)
  });
  const result = await response.json();
  if (!response.ok || !result.ok) throw new Error(result.message || 'Request failed.');
  return result;
}

export async function createWebInvoiceAndCheckout(bookingId, user) {
  const token = await user.getIdToken();
  const invoice = await postJson('api/create-web-invoice.php', token, { bookingId });
  try {
    const checkout = await postJson('api/create-checkout-session.php', token, { invoiceId: invoice.invoiceId });
    return { invoiceId: invoice.invoiceId, checkoutUrl: checkout.checkoutUrl, checkoutError: '' };
  } catch (error) {
    return { invoiceId: invoice.invoiceId, checkoutUrl: '', checkoutError: error.message };
  }
}

export async function createWebInvoice(bookingId, user) {
  return postJson('api/create-web-invoice.php', await user.getIdToken(), { bookingId });
}
