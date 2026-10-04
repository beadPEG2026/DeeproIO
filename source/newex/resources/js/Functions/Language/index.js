export function loadLanguage() {
    return axios.get(this.route('language.load')).then(response => {
        return response.data;
    })
};
