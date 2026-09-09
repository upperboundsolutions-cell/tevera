FROM eclipse-temurin:21-jdk AS build
WORKDIR /source
COPY gradlew build.gradle settings.gradle ./
COPY gradle ./gradle
COPY src ./src
COPY openapi.yaml ./
RUN chmod +x gradlew && ./gradlew --no-daemon assemble

FROM eclipse-temurin:21-jre
WORKDIR /opt/traccar
COPY --from=build /source/target/tracker-server.jar ./
COPY --from=build /source/target/lib ./lib
COPY schema ./schema
COPY templates ./templates
COPY LICENSE.txt ./
COPY deploy/traccar.xml ./conf/traccar.xml
RUN mkdir -p logs media web && chown -R 10001:10001 /opt/traccar
USER 10001:10001
ENTRYPOINT ["java", "-XX:+ExitOnOutOfMemoryError", "-jar", "tracker-server.jar", "conf/traccar.xml"]
