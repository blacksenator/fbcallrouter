FROM php:8.1-cli AS base
WORKDIR /app

RUN apt-get update && \
    apt-get install -y \
      libonig-dev \
      libxml2-dev \
      libcurl4-openssl-dev && \
    apt-get purge -y --autoremove
RUN docker-php-ext-install -j$(nproc)  \
    curl \
    mbstring \
    soap \
    sockets \
    xml

FROM base AS build

RUN apt-get update && \
    apt-get install -y \
      wget \
      unzip

COPY composer.json .
# COPY composer.lock .
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer install --no-dev --no-scripts

COPY . .
RUN composer dumpautoload --optimize

RUN wget -O vorwahlen.zip "https://www.bundesnetzagentur.de/SharedDocs/Downloads/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/Vorwahlverzeichnis_ONB.zip.zip?__blob=publicationFile&v=298" && \
    unzip vorwahlen.zip && \
    mv *.ONB.csv assets/ONB.csv && \
    rm vorwahlen.zip

FROM base AS final
ENV DOCKER_CONTAINER=true

COPY --from=build /app /app

CMD ["php", "fbcallrouter", "run"]
