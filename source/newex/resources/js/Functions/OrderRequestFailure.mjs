// A transport failure or server exception does not prove that order creation failed.
export function orderRequestFailure(error) {
    const response = error && error.response;
    const status = Number(response && response.status) || 0;
    const data = response && response.data;
    if (status === 422 && data && data.errors && typeof data.errors === 'object') {
        const fields = Object.entries(data.errors).flatMap(([field, value]) => {
            const message = Array.isArray(value) ? value[0] : value;
            return typeof message === 'string' && message.trim() ? [{field, message}] : [];
        });
        if (fields.length) return {uncertain: false, fields};
    }
    if (!status || status === 408 || status >= 500 || status < 400) {
        return {uncertain: true, fields: [], message: 'Order result is unknown. Check open orders and order history before submitting again.'};
    }
    return {uncertain: false, fields: [], message:
        status === 401 || status === 419 ? 'Your session has expired. Refresh the page before submitting again.' :
        status === 429 ? 'Too many requests. Wait before submitting again.' :
        'Order request was rejected. Review the form and try again.'};
}
