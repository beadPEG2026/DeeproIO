export function math_formatter(value, decimals) {
    return Number(value).toFixed(decimals+1).match(new RegExp('^-?\\d+(?:\.\\d{0,' + (decimals || -1) + '})?'))[0];
    //return Number((Math.floor(value * Math.pow(10, decimals)) / Math.pow(10, decimals))).toFixed(decimals);
};

export function math_percentage(value, percentage) {
    return (parseFloat(value) / 100) * parseFloat(percentage);
};

export function math_percentage_of_number(num, num2) {
    return ((100 * num) / num2).toFixed(2);
};

export function abbrNum(number) {
  
  let decPlaces = 2;
  
  // 2 decimal places => 100, 3 => 1000, etc
  decPlaces = Math.pow(10, decPlaces);

  // Enumerate number abbreviations
  var abbrev = ["k", "m", "b", "t"];

  // Go through the array backwards, so we do the largest first
  for (var i = abbrev.length - 1; i >= 0; i--) {

    // Convert array index to "1000", "1000000", etc
    var size = Math.pow(10, (i + 1) * 3);

    // If the number is bigger or equal do the abbreviation
    if (size <= parseFloat(number)) {
      // Here, we multiply by decPlaces, round, and then divide by decPlaces.
      // This gives us nice rounding to a particular decimal place.
      number = Math.round(parseFloat(number) * decPlaces / size) / decPlaces;

      // Handle special case where we round up to the next abbreviation
      if ((number == 1000) && (i < abbrev.length - 1)) {
        number = 1;
        i++;
      }

      // Add the letter for the abbreviation
      number += abbrev[i];

      // We are done... stop
      break;
    }
  }

  return number;
};